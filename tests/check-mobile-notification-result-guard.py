"""Guard MobileNotification OneSignal sends against silent failures."""

from pathlib import Path
import re

MODEL_PATH = Path("common/models/MobileNotification.php")


def function_body(source_text: str, name: str) -> str:
    """Return the PHP body for a public static method in MobileNotification."""
    match = re.search(rf"public static function {name}\s*\([^)]*\)\s*\{{", source_text)
    if not match:
        raise SystemExit(f"Could not locate {name}()")

    start = match.end() - 1
    depth = 0
    index = start
    in_string = None
    escaped = False
    in_line_comment = False
    in_block_comment = False

    while index < len(source_text):
        char = source_text[index]
        pair = source_text[index:index + 2]

        if in_line_comment:
            if char == "\n":
                in_line_comment = False
            index += 1
            continue
        if in_block_comment:
            if pair == "*/":
                in_block_comment = False
                index += 2
                continue
            index += 1
            continue
        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == in_string:
                in_string = None
            index += 1
            continue
        if pair == "//":
            in_line_comment = True
            index += 2
            continue
        if pair == "/*":
            in_block_comment = True
            index += 2
            continue
        if char == "#":
            in_line_comment = True
            index += 1
            continue
        if char in ("'", '"'):
            in_string = char
            index += 1
            continue
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source_text[start + 1:index]
        index += 1

    raise SystemExit(f"Could not parse {name}() body")


def active_php(source_text: str) -> str:
    """Remove comments before scanning active PHP code for forbidden patterns."""
    without_block_comments = re.sub(r"/\*.*?\*/", "", source_text, flags=re.S)
    return "\n".join(strip_line_comments(without_block_comments).splitlines())


def strip_line_comments(source_text: str) -> str:
    """Remove PHP line comments while preserving string literals such as URLs."""
    stripped_lines = []
    for line in source_text.splitlines():
        in_single_quote = False
        in_double_quote = False
        escaped = False

        for index, char in enumerate(line):
            if escaped:
                escaped = False
                continue
            if char == "\\" and (in_single_quote or in_double_quote):
                escaped = True
                continue
            if char == "'" and not in_double_quote:
                in_single_quote = not in_single_quote
                continue
            if char == '"' and not in_single_quote:
                in_double_quote = not in_double_quote
                continue
            if char == "/" and not in_single_quote and not in_double_quote and line[index:index + 2] == "//":
                line = line[:index]
                break
            if char == "#" and not in_single_quote and not in_double_quote:
                line = line[:index]
                break

        stripped_lines.append(line)

    return "\n".join(stripped_lines)


def call_arguments(source_text: str, call_name: str):
    """Yield argument text for function calls using balanced parentheses."""
    pattern = re.compile(rf"{re.escape(call_name)}\s*\(")

    for match in pattern.finditer(source_text):
        start = match.end() - 1
        depth = 0
        in_string = None
        escaped = False

        for index in range(start, len(source_text)):
            char = source_text[index]
            if in_string:
                if escaped:
                    escaped = False
                elif char == "\\":
                    escaped = True
                elif char == in_string:
                    in_string = None
                continue
            if char in ("'", '"'):
                in_string = char
                continue
            if char == "(":
                depth += 1
            elif char == ")":
                depth -= 1
                if depth == 0:
                    yield source_text[start + 1:index]
                    break
        else:
            raise SystemExit(f"Could not parse {call_name}() arguments")


def main() -> None:
    """Validate OneSignal notification handling and logging safety."""
    if not MODEL_PATH.exists():
        raise SystemExit(f"MobileNotification.php not found at {MODEL_PATH}")

    source = MODEL_PATH.read_text(encoding="utf-8")
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

    for error_call in call_arguments(active_send, "Yii::error"):
        for secret_pattern in ["$fields", "$apiKey", "Authorization"]:
            if secret_pattern in error_call:
                raise SystemExit(f"Notification error logging includes sensitive request material: {secret_pattern}")

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
