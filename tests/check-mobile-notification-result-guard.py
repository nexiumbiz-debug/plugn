"""Guard MobileNotification OneSignal sends against silent failures."""

from pathlib import Path
import re


MODEL = Path("common/models/MobileNotification.php")


def read_model_source() -> str:
    """Read the MobileNotification model with a clear failure if it moves."""
    if not MODEL.exists():
        raise SystemExit(f"MobileNotification.php not found at {MODEL}")
    return MODEL.read_text(encoding="utf-8")


def function_body(source_text: str, name: str) -> str:
    """Return the PHP body for a public static method in MobileNotification."""
    match = re.search(rf"public static function {name}\s*\([^)]*\)\s*\{{", source_text)
    if not match:
        raise SystemExit(f"Could not locate {name}()")

    start = match.end() - 1
    depth = 0
    index = start
    quote = ""
    escaped = False
    line_comment = False
    block_comment = False

    while index < len(source_text):
        char = source_text[index]
        next_char = source_text[index + 1] if index + 1 < len(source_text) else ""

        if line_comment:
            if char in "\r\n":
                line_comment = False
            index += 1
            continue

        if block_comment:
            if char == "*" and next_char == "/":
                block_comment = False
                index += 2
                continue
            index += 1
            continue

        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            index += 1
            continue

        if char in {"'", '"'}:
            quote = char
        elif char == "/" and next_char == "/":
            line_comment = True
            index += 1
        elif char == "#":
            line_comment = True
        elif char == "/" and next_char == "*":
            block_comment = True
            index += 1
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source_text[start + 1:index]

        index += 1

    raise SystemExit(f"Could not parse {name}() body")


def strip_php_comments(source_text: str) -> str:
    """Remove PHP comments while preserving strings such as URLs."""
    result = []
    index = 0
    quote = ""
    escaped = False
    line_comment = False
    block_comment = False

    while index < len(source_text):
        char = source_text[index]
        next_char = source_text[index + 1] if index + 1 < len(source_text) else ""

        if line_comment:
            if char in "\r\n":
                line_comment = False
                result.append(char)
            index += 1
            continue

        if block_comment:
            if char == "*" and next_char == "/":
                block_comment = False
                index += 2
                continue
            if char in "\r\n":
                result.append(char)
            index += 1
            continue

        if quote:
            result.append(char)
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            index += 1
            continue

        if char in {"'", '"'}:
            quote = char
            result.append(char)
        elif char == "/" and next_char == "/":
            line_comment = True
            index += 1
        elif char == "#":
            line_comment = True
        elif char == "/" and next_char == "*":
            block_comment = True
            index += 1
        else:
            result.append(char)

        index += 1

    return "".join(result)


def active_php(source_text: str) -> str:
    """Remove comments before scanning active PHP code for forbidden patterns."""
    return "\n".join(strip_php_comments(source_text).splitlines())


def call_args(source_text: str, call_name: str):
    """Yield balanced argument strings for calls such as Yii::error(...)."""
    search_from = 0
    while True:
        call_index = source_text.find(call_name, search_from)
        if call_index == -1:
            return

        open_index = source_text.find("(", call_index + len(call_name))
        if open_index == -1:
            return
        if source_text[call_index + len(call_name):open_index].strip():
            search_from = call_index + len(call_name)
            continue

        depth = 1
        index = open_index + 1
        args_start = index
        quote = ""
        escaped = False

        while index < len(source_text):
            char = source_text[index]

            if quote:
                if escaped:
                    escaped = False
                elif char == "\\":
                    escaped = True
                elif char == quote:
                    quote = ""
            elif char in {"'", '"'}:
                quote = char
            elif char == "(":
                depth += 1
            elif char == ")":
                depth -= 1
                if depth == 0:
                    yield source_text[args_start:index]
                    search_from = index + 1
                    break

            index += 1
        else:
            raise SystemExit(f"Could not parse {call_name}() arguments")


def main() -> None:
    """Run the notification result guard."""
    source = read_model_source()

    notify_store = function_body(source, "notifyStore")
    notify_agent = function_body(source, "notifyAgent")
    send_notification = function_body(source, "sendNotification")
    active_send = active_php(send_notification)

    helper_expectations = [
        ("notifyStore", notify_store, "oneSignalStoreAPPID", "oneSignalStoreAPIKey"),
        ("notifyAgent", notify_agent, "oneSignalAgentAPPID", "oneSignalAgentAPIKey"),
    ]

    for name, body, app_id_key, api_key in helper_expectations:
        for required in [app_id_key, api_key, "return false;", "return self::sendNotification("]:
            if required not in body:
                raise SystemExit(f"Missing {name} guard/result pattern: {required}")

    if re.search(r"^\s*curl_exec\(\$ch\);\s*$", send_notification, re.M):
        raise SystemExit("curl_exec result is still ignored")

    for forbidden in [
        "print(",
        "print_r(",
        "var_dump(",
        "die(",
    ]:
        if forbidden in active_send:
            raise SystemExit(f"Forbidden active notification debug/leak pattern remains: {forbidden}")

    for error_call in call_args(active_send, "Yii::error"):
        for secret_pattern in ["$fields", "$apiKey", "Authorization"]:
            if secret_pattern in error_call:
                raise SystemExit(
                    f"Notification error logging includes sensitive request material: {secret_pattern}"
                )

    required_patterns = [
        r"\$response\s*=\s*curl_exec\(\$ch\);",
        r"\$curlError\s*=\s*curl_error\(\$ch\);",
        r"\$httpCode\s*=\s*curl_getinfo\(\$ch,\s*CURLINFO_HTTP_CODE\);",
        r"CURLOPT_CONNECTTIMEOUT,\s*5",
        r"CURLOPT_TIMEOUT,\s*10",
        r"if\(\$response\s*===\s*false\)",
        r"if\(\$httpCode\s*<\s*200\s*\|\|\s*\$httpCode\s*>=\s*300\)",
        r"\$responseData\s*=\s*json_decode\(\$response,\s*true\);",
        r"json_last_error\(\)\s*!==\s*JSON_ERROR_NONE",
        r"empty\(\$responseData\['id'\]\)",
        r"!empty\(\$responseData\['errors'\]\)",
        r"Notification response rejected",
        r"Yii::error\(",
        r"__METHOD__",
        r"return false;",
        r"return true;",
    ]

    for pattern in required_patterns:
        if not re.search(pattern, send_notification, re.S):
            raise SystemExit(f"Missing expected OneSignal result guard pattern: {pattern}")

    print("PASS MobileNotification reports OneSignal send failures without leaking payloads")


if __name__ == "__main__":
    main()
