"""Guard payment-failed email output against raw gateway response HTML."""

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
TEMPLATE = ROOT / "common" / "mail" / "payment-failed-html.php"


def main() -> None:
    source = TEMPLATE.read_text(encoding="utf-8")

    required = [
        "use yii\\helpers\\Html;",
        "$responseText =",
        "Html::encode($payment->payment_uuid)",
        "Html::encode($payment->order_uuid)",
        "Html::encode($responseText)",
    ]
    missing = [pattern for pattern in required if pattern not in source]
    if missing:
        raise SystemExit(f"Missing escaping patterns: {missing}")

    forbidden = [
        "<?= $payment->payment_uuid ?>",
        "<?= $payment->order_uuid ?>",
        "<?= print_r($responseContent, true) ?>",
    ]
    present = [pattern for pattern in forbidden if pattern in source]
    if present:
        raise SystemExit(f"Raw payment email output patterns remain: {present}")

    pre_block = source[source.index("<pre>") : source.index("</pre>")]
    if "Html::encode($responseText)" not in pre_block:
        raise SystemExit("Payment response pre block must render encoded text.")

    print("Payment failed email escaping guard passed.")


if __name__ == "__main__":
    main()
