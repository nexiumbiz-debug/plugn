<?php
namespace common\models;

use Yii;


/**
 * Send oneSignal notifications
 * @author krushn
 */
class MobileNotification {

    /**
     *
     * @param string $headings
     * @param string $subtitle
     * @param string $content
     * @param array[
     *   [
     *       "field" => "tag",
     *       "key" => "email",
     *       "relation" => "=",
     *       "value" => $customer['customer_email']
     *   ]
     * ] $filters;
     */
    public static function notifyStore($heading, $data, $filters, $subtitle = '', $content = '')
    {
        if(!isset(Yii::$app->params['oneSignalStoreAPPID']) || !isset(Yii::$app->params['oneSignalStoreAPIKey'])) {
            return false;
        }

        return self::sendNotification(
            Yii::$app->params['oneSignalStoreAPPID'],
            Yii::$app->params['oneSignalStoreAPIKey'],
            $heading,
            $data,
            $filters,
            $subtitle,
            $content
        );
    }

    /**
     *
     * @param string $headings
     * @param string $subtitle
     * @param string $content
     * @param array[
     *   [
     *       "field" => "tag",
     *       "key" => "email",
     *       "relation" => "=",
     *       "value" => $agent['agent_email']
     *   ]
     * ] $filters;
     */
    public static function notifyAgent($heading, $data, $filters, $subtitle = '', $content = '')
    {
        if(!isset(Yii::$app->params['oneSignalAgentAPPID']) || !isset(Yii::$app->params['oneSignalAgentAPIKey'])) {
            return false;
        }

        return self::sendNotification(
            Yii::$app->params['oneSignalAgentAPPID'],
            Yii::$app->params['oneSignalAgentAPIKey'],
            $heading,
            $data,
            $filters,
            $subtitle,
            $content
        );
    }

    /**
     *
     * @param string $headings
     * @param string $subtitle
     * @param string $content
     * @param array[
     *   [
     *       "field" => "tag",
     *       "key" => "email",
     *       "relation" => "=",
     *       "value" => $agent['agent_email']
     *   ]
     * ] $filters;
     */
    public static function sendNotification($appId, $apiKey, $heading, $data, $filters, $subtitle = '', $content = '')
    {
        if(!empty(Yii::$app->params['inCodeception']))
            return true;

        $fields = [
            'app_id' => $appId,
            'filters' => $filters,
            'data' => $data,
            'contents' => ['en' => strip_tags($content)],
            'headings' => ['en' => strip_tags($heading)],
            'subtitle' => ['en' => strip_tags($subtitle)],
            'priority' => 10
            //"large_icon" => $comment['comment_by_photo'],
            //"android_group" => $groupId,
            //"collapse_id" => $groupId,
            //"android_group_message" => ["en" => "$[notif_count] new jobs"]
        ];

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8',
            'Authorization: Basic '. $apiKey));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($response === false) {
            Yii::error(
                '[OneSignal > Notification request failed] ' . $curlError,
                __METHOD__
            );
            return false;
        }

        if($httpCode < 200 || $httpCode >= 300) {
            Yii::error(
                '[OneSignal > Notification request failed] HTTP ' . $httpCode,
                __METHOD__
            );
            return false;
        }

        return true;
    }
}
