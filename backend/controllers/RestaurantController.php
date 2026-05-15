<?php

namespace backend\controllers;

use agent\models\PaymentMethod;
use backend\models\Admin;
use backend\models\PaymentSearch;
use common\models\BusinessCategory;
use common\models\BusinessItemType;
use common\models\BusinessType;
use common\models\MerchantType;
use common\models\PaymentGatewayQueue;
use common\models\RestaurantItemType;
use common\models\RestaurantPaymentMethod;
use common\models\RestaurantType;
use Yii;
use common\models\Restaurant;
use common\models\TapQueue;
use backend\models\RestaurantSearch;
use yii\db\Expression;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\web\UploadedFile;

/**
 * RestaurantCest implements the CRUD actions for Restaurant model.
 */
class RestaurantController extends Controller {

    public $enableCsrfValidation = false;

    /**
     * {@inheritdoc}
     */
    public function behaviors() {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['POST'],
                ],
            ],
            'access' => [
                'class' => \yii\filters\AccessControl::className(),
                'rules' => [
                    [
                        'allow' => Yii::$app->user->identity && Yii::$app->user->identity->admin_role != Admin::ROLE_CUSTOMER_SERVICE_AGENT,
                        'actions' => ['create', 'update', 'delete'],
                        'roles' => ['@'],
                    ],
                    [//allow authenticated users only
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionLogin($id)
    {
        $store = $this->findModel($id);

        $model = $store->getOwnerAgent()->one();

        $model->generateAuthKey();

        if(!$model->save(false)) {
            Yii::$app->session->setFlash('errorResponse', $model->errors);

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

        $url = Yii::$app->params['newDashboardAppUrl']. '?auth_key='.$model->agent_auth_key .'&store_id=' .
            $store->restaurant_uuid;

        return $this->redirect($url);
    }

    /**
     * Lists all Restaurant models.
     * @return mixed
     */
    public function actionIndex() {

        $searchModel = new RestaurantSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        $totalFilter = 0;

        if(isset(Yii::$app->request->queryParams['RestaurantSearch'])) {
            foreach (Yii::$app->request->queryParams['RestaurantSearch'] as $filter) {
                if (!empty($filter)) {
                    $totalFilter++;
                }
            }
        }

        $downloadUrl = isset(Yii::$app->request->queryParams['RestaurantSearch']) ?
            Url::to(['restaurant/export-to-excel', 'RestaurantSearch' => Yii::$app->request->queryParams['RestaurantSearch']]) :
            Url::to(['restaurant/export-to-excel']);

        return $this->render('index', [
                    'searchModel' => $searchModel,
                    'dataProvider' => $dataProvider,
            "downloadUrl" => $downloadUrl,
            'totalFilter' => $totalFilter
        ]);
    }

    public function actionExportToExcel()
    {
        /**
         * echo "<pre />";
        print_r(Yii::$app->request->queryParams);
        die();

         */
        $searchModel = new RestaurantSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        $dataProvider->query->orderBy("restaurant_created_at DESC");

        header('Access-Control-Allow-Origin: *');
        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header("Content-Disposition: attachment;filename=\"stores.xlsx\"");
        header("Cache-Control: max-age=0");

        $arrStatus = Restaurant::arrStatus();

        \common\components\PhpExcel::export([
            'isMultipleSheet' => false,
            'models' => $dataProvider->query->all(),
            'columns' => [
                'country.country_name',
                'currency.title',
                'plan.name',
                'name',
                "restaurant_domain",
                'has_deployed:boolean',
                'is_tap_enable:boolean',
                'is_sandbox:boolean',
                'is_under_maintenance:boolean',
                'restaurant.is_deleted',
                'last_active_at',
                [
                    'attribute' => 'last_order_at',
                    'value' => function($data) {
                        return !empty($data->last_order_at) ?
                            Yii::$app->formatter->asDatetime($data->last_order_at): "";
                    }
                ],
                'is_public:boolean',
                'accept_order_247:boolean',
                "total_orders",
                [
                    'attribute' => 'restaurant_status',
                    'value' => function($data) use ($arrStatus) {
                        return isset($arrStatus[$data->restaurant_status]) ?
                            $arrStatus[$data->restaurant_status]: '';
                    }
                ],
                'restaurant_uuid',
                'restaurant_created_at:datetime'
            ]
        ]);
    }

    public function actionSyncAi($id)
    {
        $store = $this->findModel($id);

        $store->generateGPTTrainingData();

        Yii::$app->session->addFlash('success', "Item data synced!");

        return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }

    public function actionFilter() {

        $searchModel = new RestaurantSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('filter', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * return list of restaurant for dropdown
     * @return string
     */
    public function actionDropdown()
    {
        $fromPager = Yii::$app->request->get('fromPager');
        //$keyword = Yii::$app->request->get('keyword');

        $searchModel = new RestaurantSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        if($fromPager) {
            return $this->renderPartial ('_dropdown_list', [
                'dataProvider' => $dataProvider,
            ]);
        }

        return $this->renderPartial('dropdown', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * process payment gateway queue
     * @return void
     */
    public function actionProcessGatewayQueue($id)
    {
        $model = $this->findModel($id);

        if(
            !$model->paymentGatewayQueue ||
            $model->paymentGatewayQueue->queue_status == PaymentGatewayQueue::QUEUE_STATUS_COMPLETE
        )
        {
            Yii::$app->session->setFlash('errorResponse', "No payment gateway request in progress");

            return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
        }

        $response = $model->paymentGatewayQueue->processQueue();

        if ($response['operation'] == 'success')
        {
            Yii::$app->session->addFlash('success', $response['message']);
        } else {
            Yii::$app->session->addFlash('error', print_r($response['message'], true));
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionForceCreateTapAccount($id)
    {
        $store = $this->findModel($id);

        $response = $store->createTapAccount(true);

        if($response["operation"] == 'error') {
            Yii::$app->session->addFlash('error', print_r($response['message'], true));
        } else {
            Yii::$app->session->addFlash('success', $response['message']);
        }

        return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }

    /**
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionResetTapCredentials($id)
    {
        $store = $this->findModel($id);

        $store->setScenario(Restaurant::SCENARIO_RESET_TAP_ACCOUNT);

        $store->is_tap_enable = false;
        $store->is_tap_created = false;

        $store->tap_merchant_status = null;
        $store->is_tap_business_active = false;

        $store->business_id = null;
        $store->business_entity_id = null;
        $store->wallet_id = null;
        $store->merchant_id = null;

        $store->operator_id = null;
        $store->live_api_key = null;
        $store->test_api_key = null;
        $store->developer_id = null;

        $store->live_public_key = null;
        $store->test_public_key = null;

        if(!$store->save()) {
            Yii::$app->session->setFlash('errorResponse', "Error: " . print_r($store->errors, true));
        }
        else
        {
            Yii::$app->session->setFlash('successResponse', 'Tap account credential removed.');
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionResetTap($id)
    {
        $store = $this->findModel($id);

        $store->setScenario(Restaurant::SCENARIO_RESET_TAP_ACCOUNT);

        $store->business_id = null;
        $store->business_entity_id = null;
        $store->wallet_id = null;
        $store->merchant_id = null;

        $store->operator_id = null;
        $store->live_api_key = null;
        $store->test_api_key = null;
        $store->developer_id = null;

        $store->live_public_key = null;
        $store->test_public_key = null;

        $store->license_number = null;

        $store->tap_merchant_status = null;

        $store->authorized_signature_issuing_date = null;
        $store->authorized_signature_expiry_date = null;

        //$store->authorized_signature_title = null;
        $store->authorized_signature_file = null;
        $store->authorized_signature_file_id = null;
        //$store->authorized_signature_file_purpose = null;
        $store->iban = null;

        $store->identification_issuing_date = null;
        $store->identification_expiry_date = null;
        $store->identification_file_front_side = null;
        $store->identification_file_id_front_side = null;
        //$store->identification_title = null;
        //$store->identification_file_purpose = null;
        $store->restaurant_email_notification = null;

        $store->commercial_license_issuing_date = null;
        $store->commercial_license_expiry_date = null;
        //$store->commercial_license_title = null;
        $store->commercial_license_file = null;
        $store->commercial_license_file_id = null;
        //$store->commercial_license_file_purpose = null;

        $store->is_tap_enable = null;
        $store->is_tap_created = null;
        $store->is_tap_business_active = null;

        $store->identification_file_back_side = null;
        $store->identification_file_id_back_side = null;

        $store->payment_gateway_queue_id = null;
        $store->tap_queue_id = null;

        if(!$store->save()) {
            Yii::$app->session->setFlash('errorResponse', "Error: " . print_r($store->errors, true));
        }
        else 
        {
            Yii::$app->session->setFlash('successResponse', 'Tap account detail removed.');
        }

        //disable gateway

        RestaurantPaymentMethod::deleteAll([
            "AND",
            ['restaurant_uuid' => $id],
            [
                'IN',
                'payment_method_id',
                PaymentMethod::find()->select('payment_method_id')->andWhere(['IN', 'payment_method_code', [
                    PaymentMethod::CODE_CREDIT_CARD,
                    PaymentMethod::CODE_KNET,
                    PaymentMethod::CODE_BENEFIT,
                    PaymentMethod::CODE_MADA
                ]])
            ]
        ]);

        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * test tap connection
     * @return void
     */
    public function actionTestTap($id)
    {
        $store = $this->findModel($id);

        Yii::$app->tapPayments->setApiKeys(
            $store->live_api_key,
            $store->test_api_key
        );//$order->restaurant->is_sandbox

        $response = Yii::$app->tapPayments->createCharge(
            $store->currency->code,
            "Testing integration", // Description
            $store->name, //Statement Desc.
            time(), // Reference
            1,
            "test name",
            "test@localhost.com",
            "+91",
            "8758702738",
            0,
            Url::to(['order/callback'], true),
            Url::to(['order/payment-webhook'], true),
            "src_kw.knet",//src_all
            0,
            0,
            'Kuwait'
        );

        $responseContent = json_decode($response->content);

        if (isset($responseContent->errors)) {
            Yii::$app->session->setFlash('errorResponse', "Error: " . $responseContent->errors[0]->code . " - " . $responseContent->errors[0]->description);
        } else {
            Yii::$app->session->setFlash('successResponse', 'Integration working fine.');
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    public function actionFetchMerchant($id)
    {
        $model = $this->findModel($id);

        $key =  $model->is_sandbox? $model->test_api_key: $model->live_api_key;

        $response = $model->fetchMerchant($model->merchant_id, $key);

        if($response["operation"] == 'error') {
            Yii::$app->session->setFlash('errorResponse', json_encode($response['message']));
        } else {
            Yii::$app->session->setFlash('successResponse', $response['message']);
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    public function actionUploadDocumentsToTap($id)
    {
        $model = $this->findModel($id);

        $response = $model->uploadDocumentsToTap();

        if($response["operation"] == 'error') {
            Yii::$app->session->setFlash('errorResponse', json_encode($response['message']));
        } else {
            Yii::$app->session->setFlash('successResponse', $response['message']);
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    public function actionCreateBusiness($id)
    {
        $model = $this->findModel($id);

        $response = $model->createBusiness();

        if($response["operation"] == 'error') {
            Yii::$app->session->setFlash('errorResponse', json_encode($response['message']));
        } else {
            Yii::$app->session->setFlash('successResponse', $response['message']);
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    public function actionCreateMerchant($id)
    {
        $model = $this->findModel($id);

        $response = $model->createMerchant();

        if($response["operation"] == 'error') {
            Yii::$app->session->setFlash('errorResponse', json_encode($response['message']));
        } else {
            Yii::$app->session->setFlash('successResponse', $response['message']);
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    public function actionCreateAnOperator($id)
    {
        $model = $this->findModel($id);

        $response = $model->createAnOperator();

        if($response["operation"] == 'error') {
            Yii::$app->session->setFlash('errorResponse', json_encode($response['message']));
        } else {
            Yii::$app->session->setFlash('successResponse', $response['message']);
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    public function actionPollTapBusinessStatus($id)
    {
        $model = $this->findModel($id);

        $response = $model->pollTapBusinessStatus();

        Yii::$app->session->setFlash('successResponse', json_encode($response));

        /*if($response["operation"] == 'error') {
            Yii::$app->session->setFlash('errorResponse', json_encode($response['message']));
        } else {
            Yii::$app->session->setFlash('successResponse', $response['message']);
        }*/

        if($model->tap_merchant_status == "Active" && $model->is_tap_business_active) {
            $model->is_tap_enable = 1;
            $model->save(true);

            $model->onTapApproved();
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionPollTapMerchantStatus($id)
    {
        $model = $this->findModel($id);

        $key =  $model->is_sandbox? $model->test_api_key: $model->live_api_key;

        $response = $model->fetchMerchant(false, $key);

        if($response["operation"] == 'error') {
            Yii::$app->session->setFlash('errorResponse', json_encode($response['message']));
        } else {
            Yii::$app->session->setFlash('successResponse', $response['message']);
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * remove payment gateway queue
     * @return void
     */
    public function actionRemoveGatewayQueue($id)
    {
        $model = $this->findModel($id);

        PaymentGatewayQueue::deleteAll(['restaurant_uuid' => $id]);

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * Displays a single Restaurant model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id) {

        $model = $this->findModel($id);

        //print_r($model->restaurantType->businessCategory);
        //die();

        $restaurantItemType = new \yii\data\ActiveDataProvider([
            'query' => $model->getRestaurantItemTypes(),
            'pagination' => false
        ]);

        $storeThemeColors = new \yii\data\ActiveDataProvider([
            'query' => $model->getRestaurantTheme(),
            'pagination' => false
        ]);

        $domainRequests = new \yii\data\ActiveDataProvider([
            'query' => $model->getRestaurantDomainRequests(),
            'pagination' => false
        ]);

        $payments = $model->getCSV();

        return $this->render('view', [
            'model' => $this->findModel($id),
            'storeThemeColors' => $storeThemeColors,
            'domainRequests' => $domainRequests,
            'restaurantItemType' => $restaurantItemType,
            "payments" => $payments
        ]);
    }

    /**
     * Delete build js
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDeleteSpecificFile($filePath, $id) {

        $store = $this->findModel($id);


        //Replace test with store branch name
        $getBuildJsSHA = Yii::$app->githubComponent->getFileSHA($filePath, $store->store_branch_name);

        if ($getBuildJsSHA->isOk && $getBuildJsSHA->data) {

            $deleteBuildJs = Yii::$app->githubComponent->deleteFile($filePath, $getBuildJsSHA->data['sha'],  $store->store_branch_name);

            if (!$deleteBuildJs->isOk) {
              Yii::error('[Github > Error While deleting'. $filePath . ']' . json_encode($deleteBuildJs->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);

              Yii::$app->session->setFlash('errorResponse', json_encode($deleteBuildJs->data['message']));
              
              return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
            }
      } else {
        Yii::error('[Github > Error while getting file sha]' . json_encode($getBuildJsSHA->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
        
        Yii::$app->session->setFlash('errorResponse', json_encode($getBuildJsSHA->data['message']));
        
        return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
      }

      return $this->redirect(['view', 'id' => $store->restaurant_uuid]);

    }

    /**
     * Merge
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionMergeToMasterBranch($id) {

        $store = $this->findModel($id);

        //Delete src/environments/environment.develop.ts file

        $getDevEnvFile = Yii::$app->githubComponent->getFileSHA('src/environments/environment.develop.ts', $store->store_branch_name);

        if ($getDevEnvFile->isOk && $getDevEnvFile->data) {

            $deleteDevEnvFile = Yii::$app->githubComponent->deleteFile('src/environments/environment.develop.ts', $getDevEnvFile->data['sha'],  $store->store_branch_name);

            if (!$deleteDevEnvFile->isOk){
              Yii::error('[Github > Error While deleting environment.develop.ts]' . json_encode($deleteDevEnvFile->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
              Yii::$app->session->setFlash('errorResponse', json_encode($deleteDevEnvFile->data['message']));
              return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
            }
        }

        //Delete branch-name.txt file
        $getBranchNameFile = Yii::$app->githubComponent->getFileSHA('branch-name.txt', $store->store_branch_name);

        if ($getBranchNameFile->isOk && $getBranchNameFile->data) {
            $deleteBranchNameFile = Yii::$app->githubComponent->deleteFile('branch-name.txt', $getBranchNameFile->data['sha'],  $store->store_branch_name);
            if (!$deleteBranchNameFile->isOk){
              Yii::error('[Github > Error While deleting branch-name.txt]' . json_encode($deleteDevEnvFile->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
              Yii::$app->session->setFlash('errorResponse', json_encode($deleteDevEnvFile->data['message']));
              return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
            }
        }


        //Delete Build.js file
        $getBuildJsSHA = Yii::$app->githubComponent->getFileSHA('build.js', $store->store_branch_name);
        if ($getBuildJsSHA->isOk && $getBuildJsSHA->data) {
            $deleteBuildJs = Yii::$app->githubComponent->deleteFile('build.js', $getBuildJsSHA->data['sha'],  $store->store_branch_name);
            if (!$deleteBuildJs->isOk){
              Yii::error('[Github > Error While deleting build.js]' . json_encode($deleteDevEnvFile->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
              Yii::$app->session->setFlash('errorResponse', json_encode($deleteDevEnvFile->data['message']));
              return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
            }
        } else {
          Yii::error('[Github > Error while getting file sha]' . json_encode($getBuildJsSHA->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
          Yii::$app->session->setFlash('errorResponse', json_encode($getBuildJsSHA->data['message']));
          return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }


        $mergeToMasterResponse = Yii::$app->githubComponent->mergeABranch('Merge branch master-temp into ' . $store->store_branch_name, $store->store_branch_name,  'master-temp');

        if ($mergeToMasterResponse->isOk) {

          $mergeToDevelopResponse = Yii::$app->githubComponent->mergeABranch('Merge branch master into ' . $store->store_branch_name, $store->store_branch_name,  'master');

          if ($mergeToDevelopResponse->isOk) {
              $store->sitemap_require_update = 1;
              $store->version = 2;
              $store->save(false);
          } else {
            Yii::error('[Github > Error While merging with master]' . json_encode($mergeToDevelopResponse->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
            Yii::$app->session->setFlash('errorResponse', json_encode($mergeToMasterResponse->data['message']));
            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
          }

        } else {
          Yii::error('[Github > Error While merging with Master-staging]' . json_encode($mergeToMasterResponse->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
          Yii::$app->session->setFlash('errorResponse', json_encode($mergeToMasterResponse->data['message']));
          return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

      return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }


    public function actionToggleDebugger($id)
    {
        $store = $this->findModel($id);

        $store->setScenario('toggleDebugger');

        $store->enable_debugger = !$store->enable_debugger;

        if ($store->save())
        {
            Yii::$app->session->setFlash('successResponse', "Store debug mode updated!");
        }
        else
        {
            Yii::$app->session->setFlash('errorResponse', $store->getErrors());
        }

        return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }

    /**
     * @param $id
     * @return void|\yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionConfigureDns($id)
    {
        $store = $this->findModel($id);

        //if custom domain

        $response = Yii::$app->netlifyComponent->createDnsZone($store);//configureDNSForSite($store->site_id);

        if ($response->isOk) {
            Yii::$app->session->setFlash('successResponse', "Success: DNS configured!");

            if (isset($response->data['dns_servers'])) {
                Yii::$app->session->setFlash('successResponse', "DNS Servers: " . implode(",", $response->data['dns_servers']));
            }

        }else
        {
            Yii::error('[Error while publishing site]' . json_encode($response->data) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);

            Yii::$app->session->setFlash('errorResponse', json_encode($response->data));
        }

        return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }

    /**
     * publish store
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionPublish($id)
    {
        $store = $this->findModel($id);

        if(empty($store->restaurant_domain)) {
            Yii::$app->session->setFlash('errorResponse', "Please set website domain!");

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

        /*if($store->site_id) {
            Yii::$app->session->setFlash('errorResponse', "Site already published!");

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }*/

        if(str_contains($store->restaurant_domain, ".plugn.site"))
        {
            Yii::$app->session->setFlash('errorResponse', "Already published!");

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }
        /*else if(str_contains($store->restaurant_domain, ".plugn.store"))
        {
            $store->restaurant_domain = str_replace(".plugn.store",
                ".plugn.site", $store->restaurant_domain);

            if(!$store->save()) {
                Yii::$app->session->setFlash('errorResponse', json_encode($store->errors));
            }

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }*/

        //if custom domain

        $response = Yii::$app->netlifyComponent->createSite($store);

        if ($response->isOk)
        {
            $store->version = Yii::$app->params['storeVersion'];

            if(!$store->site_id)
            {
                $store->site_id = $response->data['site_id'];
            }

            //$store->sitemap_require_update = 1;
            $store->save(false);

            Yii::$app->session->setFlash('successResponse', "Success: Store will be updated in 2-5 min!");
        }
        else
        {
            Yii::error('[Error while publishing site]' . json_encode($response->data) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);

            Yii::$app->session->setFlash('errorResponse', json_encode($response->data));
        }

        return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }

    /**
     * downgrade store
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionDowngrade($id)
    {
        $store = $this->findModel($id);

        if (!$store->restaurant_domain) {

            Yii::$app->session->setFlash('errorResponse', "Restaurant domain missing!");

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

        if(str_contains($store->restaurant_domain, ".plugn.store"))
        {
            Yii::$app->session->setFlash('errorResponse', "Already using older design!");

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }
        else
        //if(str_contains($store->restaurant_domain, ".plugn.site"))
        {
            $store->restaurant_domain = str_replace(".plugn.site", ".plugn.store", $store->restaurant_domain);

            /*if(!$store->save()) {
                Yii::$app->session->setFlash('errorResponse', json_encode($store->errors));
                return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
            }*/

            //$response = Yii::$app->githubComponent->mergeABranch('Merge branch master into ' . $store->store_branch_name, $store->store_branch_name,  'master');

            if ($store->site_id)
                $response = Yii::$app->netlifyComponent->downgradeSite($store);
            else
                $response = Yii::$app->netlifyComponent->createSite($store, "master");

            if ($response->isOk) {

                $store->version = Yii::$app->params['storeVersion'];

                if (!$store->site_id && isset($response->data['site_id'])) {
                    $store->site_id = $response->data['site_id'];
                }

                //$store->sitemap_require_update = 1;
                $store->save(false);

                Yii::$app->session->setFlash('successResponse', "Success: Store will be updated in 2-5 min!");
            } else {
                Yii::error('[Error while downgrading site]' . json_encode($response->data) . ' RestaurantUuid: ' . $store->restaurant_uuid, __METHOD__);

                Yii::$app->session->setFlash('errorResponse', json_encode($response->data));
            }

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }
    }

    /**
     * upgrade store
     * @param $id
     * @return \yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionUpgrade($id)
    {
        $store = $this->findModel($id);

        if (!$store->restaurant_domain) {

            Yii::$app->session->setFlash('errorResponse', "Restaurant domain missing!");

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

        if(str_contains($store->restaurant_domain, ".plugn.site"))
        {
            Yii::$app->session->setFlash('errorResponse', "Already using new design!");

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }
        else if(str_contains($store->restaurant_domain, ".plugn.store"))
        {
            $store->restaurant_domain = str_replace(".plugn.store", ".plugn.site", $store->restaurant_domain);

            if(!$store->save()) {
                Yii::$app->session->setFlash('errorResponse', json_encode($store->errors));
            }

            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

        //if custom domain

        //$response = Yii::$app->githubComponent->mergeABranch('Merge branch master into ' . $store->store_branch_name, $store->store_branch_name,  'master');

        if($store->site_id)
            $response = Yii::$app->netlifyComponent->upgradeSite($store);
        else
            $response = Yii::$app->netlifyComponent->createSite($store);

        if ($response->isOk)
        {
            $store->version = Yii::$app->params['storeVersion'];

            if(!$store->site_id && isset($response->data['site_id']))
            {
                $store->site_id = $response->data['site_id'];
            }

            //$store->sitemap_require_update = 1;
            $store->save(false);

            Yii::$app->session->setFlash('successResponse', "Success: Store will be updated in 2-5 min!");
        }
        else
        {
            Yii::error('[Error while upgrading site]' . json_encode($response->data) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);

            Yii::$app->session->setFlash('errorResponse', json_encode($response->data));
        }

        return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }

    /**
     * Update sitemap
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdateSitemap($id) {

        $store = $this->findModel($id);

        $dirName = "../runtime/store";
        $storeDir = $dirName . "/" . $store->store_branch_name;
        $sitemapPath = $storeDir . "/sitemap.xml";

        if (!is_dir($dirName) && !mkdir($dirName, 0755, true) && !is_dir($dirName)) {
            Yii::error('[Sitemap > Runtime directory unavailable] RestaurantUuid: ' . $store->restaurant_uuid, __METHOD__);
            Yii::$app->session->setFlash('errorResponse', 'Unable to prepare sitemap directory.');
            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

        if (!is_dir($storeDir) && !mkdir($storeDir, 0755, true) && !is_dir($storeDir)) {
            Yii::error('[Sitemap > Store directory unavailable] Path: ' . $storeDir . ' RestaurantUuid: ' . $store->restaurant_uuid, __METHOD__);
            Yii::$app->session->setFlash('errorResponse', 'Unable to prepare store sitemap directory.');
            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
        }

      $sitemap = fopen($sitemapPath, "w");
      if ($sitemap === false) {
          Yii::error('[Sitemap > File open failed] Path: ' . $sitemapPath . ' RestaurantUuid: ' . $store->restaurant_uuid, __METHOD__);
          Yii::$app->session->setFlash('errorResponse', 'Unable to create sitemap file.');
          return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
      }

      if (fwrite($sitemap, Yii::$app->fileGeneratorComponent->createSitemapXml($store->restaurant_uuid)) === false) {
          fclose($sitemap);
          Yii::error('[Sitemap > File write failed] Path: ' . $sitemapPath . ' RestaurantUuid: ' . $store->restaurant_uuid, __METHOD__);
          Yii::$app->session->setFlash('errorResponse', 'Unable to write sitemap file.');
          return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
      }
      fclose($sitemap);

      $fileToBeUploaded = file_get_contents($sitemapPath);
      if ($fileToBeUploaded === false) {
          Yii::error('[Sitemap > File read failed] Path: ' . $sitemapPath . ' RestaurantUuid: ' . $store->restaurant_uuid, __METHOD__);
          Yii::$app->session->setFlash('errorResponse', 'Unable to read sitemap file.');
          return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
      }

      // Encode the image string data into base64
      $data = base64_encode($fileToBeUploaded);


      //Replace test with store branch name
      $getSitemapXmlSHA = Yii::$app->githubComponent->getFileSHA('src/sitemap.xml', $store->store_branch_name);

      if ($getSitemapXmlSHA->isOk && $getSitemapXmlSHA->data) {

          $commitSitemapXmlFileResponse = Yii::$app->githubComponent->createFileContent($data, $store->store_branch_name, 'src/sitemap.xml', 'Update sitemap', $getSitemapXmlSHA->data['sha']);

          if ($commitSitemapXmlFileResponse->isOk) {

            if($store->sitemap_require_update == 1){
              $store->sitemap_require_update = 0;
              $store->save(false);
            }

            $dirPath = $storeDir;
            $file_pointer =  $sitemapPath;

            // Use unlink() function to delete a file
            if (!unlink($file_pointer)) {
                Yii::error("$file_pointer cannot be deleted due to an error", __METHOD__);
            } else {
                if (!rmdir($dirPath)) {
                    Yii::error("Could not remove $dirPath", __METHOD__);
                }
            }

          } else {
            Yii::error('[Github > Commit sitemap Xml]' . json_encode($commitSitemapXmlFileResponse->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
            return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
          }
    } else {
      Yii::error('[Github > Error while getting file sha]' . json_encode($getSitemapXmlSHA->data['message']) . ' RestaurantUuid: '. $store->restaurant_uuid, __METHOD__);
      return $this->redirect(['view', 'id' => $store->restaurant_uuid]);
    }

      return $this->redirect(['view', 'id' => $store->restaurant_uuid]);

    }

    /**
     * update store type details
     * @param $id
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionRestaurantType($id)
    {
        $model = $this->findModel($id);

        $restaurantType = $model->restaurantType;

        if(!$restaurantType) {
            $restaurantType = new RestaurantType();
            $restaurantType->restaurant_uuid = $id;
        }

        if (Yii::$app->request->isPost && $restaurantType->load(Yii::$app->request->post())) {

            $transaction = Yii::$app->db->beginTransaction();

            if (!$restaurantType->save()) {

                $transaction->rollBack();

                Yii::$app->session->setFlash('error', print_r($restaurantType->errors, true));
                return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
            }

            $restaurantItemTypes = Yii::$app->request->getBodyParam('restaurantItemTypes');
                //$restaurantType->arrRestaurantItemTypes;

            if(!$restaurantItemTypes) {
                $restaurantItemTypes = [];
            }

            $arrRitUUID = [];

            foreach ($restaurantItemTypes as $business_item_type_uuid)//$restaurantItemType
            {
                $rtModel = null;

                //if(!empty($restaurantItemType['rit_uuid'])) {

                    $rtModel = RestaurantItemType::findOne([
                        //'rit_uuid' => $restaurantItemType['rit_uuid'],
                        'business_item_type_uuid' => $business_item_type_uuid,
                        'restaurant_uuid' => $model->restaurant_uuid
                    ]);
                //}

                if(!$rtModel) {
                    $rtModel = new RestaurantItemType();
                }

                $rtModel->restaurant_uuid = $id;
                $rtModel->business_item_type_uuid = $business_item_type_uuid;
                //$restaurantItemType['business_item_type_uuid'];

                if(!$rtModel->save()) {

                    $transaction->rollBack();

                    Yii::$app->session->setFlash('error', print_r($rtModel->errors, true));
                    return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
                }

                $arrRitUUID[] = $rtModel->rit_uuid;
            }

            //delete removed store item type

            RestaurantItemType::deleteAll([
                "AND",
                ['restaurant_uuid' => $model->restaurant_uuid],
                ['NOT IN', 'rit_uuid', $arrRitUUID]
            ]);

            $transaction->commit();

            //todo: send mixpanel event

                $itemTypes = [];

                foreach ($model->restaurantItemTypes as $restaurantItemType) {
                    $itemTypes[] = $restaurantItemType->businessItemType->business_item_type_en;
                }

                Yii::$app->eventManager->track('Store type', [
                        'restaurant' => $model->name,
                        'merchant_type' => $restaurantType->merchantType? $restaurantType->merchantType->merchant_type_en: null,
                        'business_type' => $restaurantType->businessType? $restaurantType->businessType->business_type_en: null,
                        'business_category' => $restaurantType->businessCategory? $restaurantType->businessCategory->business_category_en: null,
                        'itemTypes' => $itemTypes
                    ],
                    null,
                    $model->restaurant_uuid);

            return $this->redirect(['view', 'id' => $model->restaurant_uuid, 'tab' => 'restaurantType']);
        }

        $merchantTypes = ArrayHelper::map(MerchantType::find()->all(), 'merchant_type_uuid', 'merchant_type_en');
        $businessTypes = ArrayHelper::map(BusinessType::find()->all(), 'business_type_uuid', 'business_type_en');
        $businessCategories = ArrayHelper::map(BusinessCategory::find()->all(), 'business_category_uuid', 'business_category_en');
        $businessItemTypes = BusinessItemType::find()->all();

        $restaurantItemTypes = ArrayHelper::getColumn($model->restaurantItemTypes, 'business_item_type_uuid');

        return $this->render('restaurant_type', [
            'model' => $model,
            'restaurantType' => $restaurantType,
            'merchantTypes' => $merchantTypes,
            'businessTypes' => $businessTypes,
            'businessCategories' => $businessCategories,
            'businessItemTypes' => $businessItemTypes,
            'restaurantItemTypes' => $restaurantItemTypes
        ]);
    }

    /**
     * Updates an existing Restaurant model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id) {

        $model = $this->findModel($id);

        if (Yii::$app->request->isPost && $model->load(Yii::$app->request->post())) {

            if ($model->restaurant_payments_method)
                $model->saveRestaurantPaymentMethod($model->restaurant_payments_method);


            if ($model->save(false)) {

                $thumbnail_image = UploadedFile::getInstances($model, 'restaurant_thumbnail_image');

                $logo = UploadedFile::getInstances($model, 'restaurant_logo');

                if ($thumbnail_image)
                    $model->uploadThumbnailImage($thumbnail_image[0]->tempName);

                if ($logo)
                    $model->uploadLogo($logo[0]->tempName);

                return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
            }else {
                Yii::$app->session->setFlash('error', print_r($model->errors, true));
            }
        }

        if ($model->owner_number && !str_starts_with($model->owner_number, $model->owner_phone_country_code)) {
            $model->owner_number = '+' . $model->owner_phone_country_code . " ".  $model->owner_number;
        }

        if ($model->phone_number && !str_starts_with($model->phone_number, $model->phone_number_country_code)) {
            $model->phone_number = '+' .$model->phone_number_country_code . " ". $model->phone_number;
        }

        return $this->render('update', [
                    'model' => $model,
        ]);
    }

    /**
     * Display request driver button on order detail page
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDisplayRequestDriverButton($id) {
        $model = $this->findModel($id);
        $model->hide_request_driver_button = 0;
        $model->save(false);

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * Hide request driver button on order detail page
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionHideRequestDriverButton($id) {
        $model = $this->findModel($id);
        $model->hide_request_driver_button = 1;
        $model->save(false);

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * Change restaurant status to become open
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionPromoteToOpen($id) {
        $model = $this->findModel($id);
        $model->restaurant_status = Restaurant::RESTAURANT_STATUS_OPEN;
        $model->save(false);

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * Change restaurant status to become busy
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionPromoteToBusy($id) {
        $model = $this->findModel($id);
        $model->restaurant_status = Restaurant::RESTAURANT_STATUS_BUSY;
        $model->save(false);

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * Change restaurant status to become close
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionPromoteToClose($id) {
        $model = $this->findModel($id);
        $model->restaurant_status = Restaurant::RESTAURANT_STATUS_CLOSED;
        $model->save(false);

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    public function actionUndoDelete($id)
    {
        $model = $this->findModel($id);

        $model->is_deleted = false;

        if(!$model->save(false)) {
            Yii::$app->session->setFlash('error', print_r($model->errors, true));
        }

        return $this->redirect(['view', 'id' => $model->restaurant_uuid]);
    }

    /**
     * Deletes an existing Restaurant model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        $model->deleteSite();

        return $this->redirect(['index']);
    }

    /**
     * Finds the Restaurant model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Restaurant the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id) {
        if (($model = Restaurant::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException('The requested page does not exist.');
    }

}
