from pathlib import Path
import re

component = Path("common/components/EventManager.php")
source = component.read_text(encoding="utf-8")

match = re.search(
    r"catch\s*\(AwsException\s+\$e\)\s*\{(?P<body>.*?)\n\s*\}",
    source,
    re.S,
)

if not match:
    raise SystemExit("Could not locate EventManager AwsException catch block")

body = match.group("body")

for forbidden in [
    "echo $e->getMessage()",
    "print_r($e",
    "var_dump($e",
    "die(",
    "$e->getMessage()",
    "Yii::debug(\"Error sending message:",
]:
    if forbidden in body:
        raise SystemExit(f"Forbidden SQS exception response/debug pattern remains: {forbidden}")

required_patterns = [
    r"Yii::error\(",
    r"\[EventManager > SQS send failed\]",
    r"\$e->getAwsErrorCode\(\)",
    r"\$e->getStatusCode\(\)",
    r"__METHOD__",
]

for pattern in required_patterns:
    if not re.search(pattern, body, re.S):
        raise SystemExit(f"Missing expected SQS exception guard pattern: {pattern}")

print("PASS EventManager logs SQS send failures without echoing provider errors")
