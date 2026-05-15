<?php 
namespace common\components;

use agent\models\AgentAssignment;
use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use Segment\Segment;
use Yii;
use yii\base\Component;

class EventManager extends Component
{
    private $_client;

    /**
     * @var string Mixpanel key for BAWES project
     */
    private $_walletClient;

    private $_sqsClient;

    public $sqsRagion;
    public $sqsKey;
    public $sqsSecret;
    public $sqsQueue;

    /**
     * @var string Mixpanel key
     */
    public $key;

    /**
     * @var string | null Mixpanel key for BAWES project
     */
    public $walletMixpanelKey;

    /**
     * @var string | null Mixpanel status
     */
    public $mixpanelStatus;

    /**
     * @var string | null Segment status
     */
    public $segmentStatus;

    /**
     * @var string | null Segment key
     */
    public $segmentKey;

    /**
     * @var string | null Segment key for wallet app
     */
    public $walletSegmentKey;

    /**
     * @var boolean Whether segment identity defined
     */
    private $segmentIdentify;

    public $walletEvents = [
        "Order Placed",
        "Premium Plan Purchase",
        "Invoice Paid"
    ];

     /**
     * @inheritdoc
     */
    public function init()
    {
        /*if ($this->key === null) {
            throw new InvalidConfigException(strtr('"{class}::{attribute}" cannot be empty.', [
                '{class}' => static::className(),
                '{attribute}' => '$key'
            ]));
        }*/

        parent::init();

        //'key' => 'bfe2ac5e039a3d8d1c8e281967d6f954',//test: ac62dbe81767f8871f754c7bdf6669d6
        //'segmentKey' => 'WZc7uvfkM1uhsjT1Eie6PONXFZK3ME15'//test: 7oEpdGxjwBMlwBQYuXD7NpYWp4HzDJWh
        //'walletSegmentKey' => 'j18MpMF6fvZzmc6bvF0VjlTajAlKwai2'//test: 7oEpdGxjwBMlwBQYuXD7NpYWp4HzDJWh

        $this->segmentStatus = Yii::$app->config->get('Segment-Status');
        $this->mixpanelStatus = Yii::$app->config->get('Mixpanel-Status');

        if($this->mixpanelStatus) {

            if (YII_ENV == 'prod') {
                $this->key = Yii::$app->config->get('Mixpanel-Key');
                $this->walletMixpanelKey = Yii::$app->config->get('Mixpanel-Key-Wallet');
            } else {
                $this->key = Yii::$app->config->get('Test-Mixpanel-Key');
                $this->walletMixpanelKey = Yii::$app->config->get('Test-Mixpanel-Key-Wallet');
            }

            $this->_client = \Mixpanel::getInstance($this->key);

            if ($this->walletMixpanelKey) {
                $this->_walletClient = \Mixpanel::getInstance($this->walletMixpanelKey);
            }
        }

        if($this->segmentStatus) {

            if (YII_ENV == 'prod') {
                $this->segmentKey = Yii::$app->config->get('Segment-Key');
                $this->walletSegmentKey = Yii::$app->config->get('Segment-Key-Wallet');
            } else {
                $this->segmentKey = Yii::$app->config->get('Test-Segment-Key');
                $this->walletSegmentKey = Yii::$app->config->get('Test-Segment-Key-Wallet');
            }

            if($this->segmentKey)
                Segment::init($this->segmentKey);
        }

        // Create an SQS client
        if ($this->sqsSecret && $this->sqsKey && $this->sqsRagion)
        {
            $this->_sqsClient = new SqsClient([
                'region' => $this->sqsRagion, // Replace with your region
                'version' => 'latest',
                'credentials' => [
                    'key'    => $this->sqsKey, // Replace with your access key
                    'secret' => $this->sqsSecret, // Replace with your secret key
                ],
            ]);
        }
    }

    /**
     * @param $key
     * @return void
     */
    public function initSegment($key) {

        $this->segmentKey = $key;

        Segment::init($key);
    }

    /**
     * set user for trackinng/event management
     */     
    public function setUser($id, $data) 
    {
        try {
            $ip = Yii::$app->getRequest()->getUserIP();
        } catch (yii\base\UnknownMethodException $exception) {
            $ip = "192.168.0.1";
        }

        if($this->_client) {
            $this->_client->identify($id);
            $this->_client->people->set($id, $data, $ip, $ignore_time = false);
        }

        if ($this->_walletClient) {
            $this->_walletClient->identify($id);
            $this->_walletClient->people->set($id, $data, $ip, $ignore_time = false);
        }

        if($this->segmentKey) {
            Segment::identify([
                "userId" => $id,
                "traits" => $data
            ]);

            $this->segmentIdentify = true;
        }
    }

    /**
     * todo: userId not passing in mixpanel?
     * register event 
     */
    public function track($event, $eventData, $timestamp = null, $storeId = null, $onlyWallet = false)
    {
        $distinctID = null;

        if(isset(Yii::$app->request) && Yii::$app->request instanceof \yii\web\Request) {

            if(!$storeId) {
                $storeId = Yii::$app->request->headers->get('Store-Id');
            }

            $distinctID = Yii::$app->request->headers->get('Mixpanel-Distinct-ID');
        }

        $language = Yii::$app->language == "ar"? "ar": "en";

        $eventData = array_merge($eventData, [
            "company_id" => $storeId,
            "store_id" => $storeId,
            "language" => $language,
            "do_not_disturb" => null,
        ]);

        //if login

        if(isset(Yii::$app->user) && !Yii::$app->user->isGuest) {

            //if agent login

            if(isset(Yii::$app->user->identity->agent_name)) {

                $assignment = AgentAssignment::find()
                    ->andWhere(['restaurant_uuid' => $storeId, "agent_id" => Yii::$app->user->getId()])
                    ->one();

                if ($assignment)
                    $eventData["role"] = $assignment->role;

                $eventData["channel"] = "Dashboard Web App";
            }
            //if customer login
            else if(isset(Yii::$app->user->identity->customer_id))
            {
                $eventData["channel"] = "Store Web App";
            }
        }

        if(empty($eventData["channel"])) {
            $eventData["channel"] = "Backend";
        }

        //if login and userId not provided

        /*if(is_null($userId) && isset(Yii::$app->user) && !Yii::$app->user->isGuest) {
            $userId = Yii::$app->user->getId();
        }*/

        if (empty(Yii::$app->user) || Yii::$app->user->isGuest) {
            $userId = $storeId;
        } else {
            $userId = Yii::$app->user->getId();

            /*if($this->_client) {
                $this->_client->identify($userId);
                $this->_client->people->set($userId, [
                    'name' => trim(Yii::$app->user->identity->agent_name),
                    'email' => Yii::$app->user->identity->agent_email,
                ]);
            }*/
        }

        if($this->_client) {
            
            $mixpanelData = $eventData;
            
            if($timestamp) {
                $mixpanelData =  array_merge([
                    "\$time" => strtotime($timestamp),
                    "\$created" => $timestamp,
                ], $eventData);
            }

            if($distinctID) {
                $mixpanelData['$distinct_id'] = $distinctID;
            }

            if($userId) {
                $mixpanelData['$user_id'] = $userId;
                $mixpanelData['user_id'] = $userId;

                if(empty($mixpanelData['$distinct_id'])) {
                    $mixpanelData['$distinct_id'] = $userId;
                }
            } else if (isset($mixpanelData['$distinct_id'])) {
                $mixpanelData['$user_id'] = $mixpanelData['$distinct_id'];
            }

            //to fix: not showing in listing but in detail view in mixpanel

            if(isset($mixpanelData['$distinct_id']))
                $mixpanelData['distinct_id'] = $mixpanelData['$distinct_id'];

            //if wallet event, send to wallet/ main project
            if ($this->_walletClient && in_array($event, $this->walletEvents)) {

                $this->_walletClient->track("Revenue", $mixpanelData);
                $this->_walletClient->flush();

                if ($onlyWallet)
                    return true;
            }

            $this->_client->track($event, $mixpanelData);

            //to fix order
            $this->_client->flush();
        }
        
        if($this->segmentKey) {

            $data = [
                'event' => $event,
                'properties' => $eventData,
                'timestamp' => $timestamp
            ];

            if(!$userId) {
                $userId = "anonymous";
            }

            if($this->segmentIdentify)  {
                $data['userId'] = $userId;
            } else {
                $data['anonymousId'] = $userId;
            }

            Segment::track($data);

            Segment::flush();
        }

        // send to queue

        if ($this->_sqsClient && $this->sqsQueue) {
            $queueUrl = 'https://sqs.' . $this->sqsRagion . '.amazonaws.com/' . $this->sqsQueue; // Replace with your queue URL

            //if login and userId not provided

            if(is_null($userId) && isset(Yii::$app->user) && !Yii::$app->user->isGuest) {
                $userId = Yii::$app->user->getId();
            }

            if(!$userId) {
                $userId = "anonymous";
            }

            $data = array_merge($eventData, [
                "login_user_id" => $userId
            ]);

            try {
                $result = $this->_sqsClient->sendMessage([
                    'QueueUrl' => $queueUrl,
                    'MessageBody' => json_encode($data),
                ]);

                Yii::debug("Message sent! Message ID: " . $result->get('MessageId'));

            } catch (AwsException $e) {
                Yii::error(
                    '[EventManager > SQS send failed] AWS error code: ' . ($e->getAwsErrorCode() ?: 'n/a') .
                    ' HTTP status: ' . ($e->getStatusCode() ?: 'n/a'),
                    __METHOD__
                );
            }
        }
    }

    public function flush()
    {
        if($this->segmentKey)
            Segment::flush();
    }
}
