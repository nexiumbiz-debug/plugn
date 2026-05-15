"""Guard MobileNotification OneSignal sends against silent failures."""

from pathlib import Path
import re

model = Path("common/models/MobileNotification.php")
source = model.read_text(encoding="utf-8")


def function_body(name: str) -> str:
    """Return the PHP body for a public static method in MobileNotification."""
    match = re.search(rf"public static function {name}\s*\([^)]*\)\s*\{{", source)
    if not match:
        raise SystemExit(f"Could not locate {name}()")

    start = match.end() - 1
    depth = 0
    for index in range(start, len(source)):
        char = source[index]
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[start + 1:index]

    raise SystemExit(f"Could not parse {name}() body")


def active_php(source_text: str) -> str:
    """Remove comments before scanning active PHP code for forbidden patterns."""
    without_block_comments = re.sub(r"/\*.*?\*/", "", source_text, flags=re.S)
    return re.sub(r"(?m)(^|[^:\"'])//[^\n]*", r"\1", without_block_comments)


notify_store = function_body("notifyStore")
notify_agent = function_body("notifyAgent")
send_notification = function_body("sendNotification")
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

for error_call in re.findall(r"Yii::error\s*\((.*?)\);", active_send, re.S):
    for secret_pattern in ["$fields", "$apiKey", "Authorization"]:
        if secret_pattern in error_call:
            raise SystemExit(f"Notification error logging includes sensitive request material: {secret_pattern}")

required_patterns = [
    r"\$response\s*=\s*curl_exec\(\$ch\);",
    r"\$curlError\s*=\s*curl_error\(\$ch\);",
    r"\$httpCode\s*=\s*curl_getinfo\(\$ch,\s*CURLINFO_HTTP_CODE\);",
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
