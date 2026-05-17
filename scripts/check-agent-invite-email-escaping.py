"""Validate that agent invitation email templates escape dynamic values."""

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


CHECKS = {
    "common/mail/agent-invitation.php": {
        "required": [
            "$frontendUrl = Html::encode((string) Yii::$app->params['frontendUrl']);",
            "$agentName = Html::encode((string) $model->agent->agent_name);",
            "$restaurantName = Html::encode((string) $model->restaurant->name);",
            "$restaurantDomain = Html::encode((string) $model->restaurant->restaurant_domain);",
            "Hi <?= $agentName ?>,",
            "href='<?= $restaurantDomain ?>'",
            "><?= $restaurantName ?></a>",
        ],
        "forbidden": [
            "<?= $model->agent->agent_name ?>",
            "<?= $model->restaurant->name ?>",
            "href='<?= $model->restaurant->restaurant_domain ?>'",
        ],
    },
    "common/mail/new-agent.php": {
        "required": [
            "$frontendUrl = Html::encode((string) Yii::$app->params['frontendUrl']);",
            "$agentName = Html::encode((string) $model->agent->agent_name);",
            "$restaurantName = Html::encode((string) $model->restaurant->name);",
            "$restaurantDomain = Html::encode((string) $model->restaurant->restaurant_domain);",
            "$assignmentAgentEmail = Html::encode((string) $model->assignment_agent_email);",
            "$encodedPassword = Html::encode((string) $password);",
            "Hello <?= $agentName ?>,",
            "href='<?= $restaurantDomain ?>'",
            "><?= $restaurantName ?></a>",
            "<td><?= $assignmentAgentEmail ?></td>",
            "<td><?= $encodedPassword ?></td>",
        ],
        "forbidden": [
            "<?= $model->agent->agent_name ?>",
            "<?= $model->restaurant->name ?>",
            "href='<?= $model->restaurant->restaurant_domain ?>'",
            "<td><?= $model->assignment_agent_email ?></td>",
            "<td><?= $password ?></td>",
        ],
    },
}


def main() -> None:
    """Check required escaped snippets and reject raw template output."""
    failures = []

    for relative_path, expectations in CHECKS.items():
        content = (ROOT / relative_path).read_text(encoding="utf-8")

        for snippet in expectations["required"]:
            if snippet not in content:
                failures.append(f"{relative_path} is missing expected escaped output: {snippet}")

        for snippet in expectations["forbidden"]:
            if snippet in content:
                failures.append(f"{relative_path} still contains raw output: {snippet}")

    if failures:
        raise SystemExit("\n".join(failures))

    print("Agent invitation email escaping checks passed.")


if __name__ == "__main__":
    main()
