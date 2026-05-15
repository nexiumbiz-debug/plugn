<?php

use yii\helpers\Html;

$responseText = is_scalar($responseContent) || $responseContent === null
    ? (string)$responseContent
    : print_r($responseContent, true);
?>

<h2>Payment</h2>

<p><b>Payment uuid</b>: <?= Html::encode($payment->payment_uuid) ?> </p>

<p><b>Order uuid</b>: <?= Html::encode($payment->order_uuid) ?> </p>

<h2>Response</h2>

<pre><?= Html::encode($responseText) ?></pre>

