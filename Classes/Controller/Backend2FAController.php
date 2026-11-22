<?php

declare(strict_types=1);

namespace Miniorange\Otp\Controller;

use Exception;
use Miniorange\Otp\Domain\Repository\Site\SiteRepository;
use Miniorange\Otp\Helper\MoUtilities;
use Miniorange\Otp\Helper\CustomerMo;
use Miniorange\Otp\Helper\Constants;
use Miniorange\Otp\Helper\Messages;
use Miniorange\Otp\Helper\TypeAssert;
use Miniorange\Otp\Services\AuthenticatorEnrollmentLimiter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\Renderer\ListRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;


/**
 *This class handles saving the 2FA Settings.
 */
class Backend2FAController extends ActionController
{

    protected ?ResponseInterface $response = null;
    protected string $tab = '';
    protected ?string $selectedProvider = null;
    protected PageRenderer $pageRenderer;

    protected SiteRepository $siteRepository;

    public function __construct(PageRenderer $pageRenderer, SiteRepository $siteRepository)
    {
        $this->pageRenderer = $pageRenderer;
        $this->siteRepository = $siteRepository;
    }

    /**
     * @throws Exception
     */
    public function requestAction(): ResponseInterface
    {

        error_log('=== Backend2FAController::requestAction START ===');
        error_log('Request method: ' . TypeAssert::stringValue($_SERVER['REQUEST_METHOD'] ?? null));
        $post = TypeAssert::stringKeyedArray($_POST);
        if ($post === []) {
            $post = TypeAssert::parsedBodyArray($this->request->getParsedBody());
        }
        error_log('POST data: ' . print_r($post, true));

        if (isset($post['option']) && $post['option'] == "mo_support_submit" && $this->isXmlHttpRequest()) {
            error_log("Support form submitted");

            return $this->sendJsonResponse($this->handleSupportFormSubmission($post));
        }
    
        
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('@miniorange/mfa/Resources/Public/Css/otp/bootstrap.min.css');
        $pageRenderer->addCssFile('@miniorange/mfa/Resources/Public/Css/otp/font-awesome.min.css');
        $pageRenderer->addCssFile('@miniorange/mfa/Resources/Public/Css/otp/main.css');
        $pageRenderer->loadJavaScriptModule('@miniorange/mfa/jquery.min.js');
        $pageRenderer->loadJavaScriptModule('@miniorange/mfa/bootstrap.min.js');
        $pageRenderer->loadJavaScriptModule('@miniorange/mfa/main.js');

        if (isset($post['option']) and $post['option'] == "save_customer_2fa_settings") {
            $this->saveCustomer2faRuleAction($post);
            $this->view->assign('twofaRules', $this->getAll2faRules());
        }

        if (isset($post['option']) and $post['option'] == "add_new_rule") {
            $this->tab = "Twofa_Setting_Choose";
            $this->view->assign('tab', $this->tab);
            $this->view->assign('editMode', false);
        }

        // update rule
        if (isset($post['option']) and $post['option'] == "update_2fa_settings") {
            $this->update2faRuleAction($post);
            $this->view->assign('twofaRules', $this->getAll2faRules());
        }

        if (isset($post['option']) and $post['option'] == "edit_customer_2fa_settings") {
            $id = TypeAssert::intValue($post['rule_id'] ?? null);
            $type = TypeAssert::stringValue($post['rule_type'] ?? null);
            $rule = $this->get2faRuleById($id);


            
            if ($rule) {
                // Set the tab based on rule type
                if ($type == 'Admin') {
                    $this->tab = "Admin_Configuration";
                } else {
                    $this->tab = "Customer_Configuration";
                }
                
                // Assign the tab to the view
                $this->view->assign('tab', $this->tab);
                

                // Assign edit data for JavaScript
                $this->view->assign('editRuleData', json_encode($rule));
                $this->view->assign('editRuleType', $type);
                $this->view->assign('editMode', true);
                // add rule id and rule type to the view
                $this->view->assign('editRuleId', $id);
                $this->view->assign('editRuleType', $type);
                
               
                $this->view->assign('editRule', $rule);

                $this->view->assign('otpLength', TypeAssert::intValue($rule['otp_length'] ?? null, 6));
                
                // Continue with the rest of the action to load necessary data
                $this->view->assign('twofaRules', $this->getAll2faRules());
            } else {
                MoUtilities::showErrorFlashMessage('Rule not found!');
                $this->tab = "Twofa_Setting";
                $this->view->assign('tab', $this->tab);
            }
        }

        if (isset($post['option']) and $post['option'] == "delete_customer_2fa_rule") {
            $this->delete2faRuleAction($post);
            // $this->tab = "Apps";
            $this->view->assign('twofaRules', $this->getAll2faRules());
        }

        if (isset($post['option']) and $post['option'] == "save_admin_2fa_settings") {
            $this->saveAdmin2faRuleAction($post);
            $this->view->assign('twofaRules', $this->getAll2faRules());
        }
        
        // Handle edit 2FA rule
        if (isset($post['option']) and $post['option'] == "update_customer_2fa_settings") {
            $this->update2faRuleAction($post);
            $this->tab = "Apps";
            $this->view->assign('twofaRules', $this->getAll2faRules());
        }

        // handle reset 2fa
        if (isset($post['option']) and $post['option'] == "bulk_reset_2fa") {
            $this->reset2faUserAction($post);
            $this->tab = "User_Management";
            $this->view->assign('tab', $this->tab);
        }

        // handle disable 2fa
        if (isset($post['option']) and $post['option'] == "bulk_disable_2fa") {
            $this->disable2faUserAction($post);
            $this->tab = "User_Management";
            $this->view->assign('tab', $this->tab);
        }

        // handle support form submission
        if (isset($post['option']) && $post['option'] == "mo_support_submit") {
            error_log("Support form submitted");

            $result = $this->handleSupportFormSubmission($post);
            if ($this->isXmlHttpRequest()) {
                return $this->sendJsonResponse($result);
            }

            $currentTab = TypeAssert::stringValue($post['current_tab'] ?? null);
            if ($currentTab !== '') {
                $this->view->assign('tab', $currentTab);
            }
            $this->view->assign('supportSubmitted', $result['status'] === 'success');
            $this->view->assign('supportMessage', TypeAssert::stringValue($result['message']));
            $this->view->assign('supportMessageType', TypeAssert::stringValue($result['status']));
        }

        //------------ HANDLING SUPPORT QUERY---------------
        elseif (isset($post['option']) and $post['option'] == "mo_oidc_contact_us_query_option") {
            $this->support();
        } 
        elseif (isset($post['option']) and $post['option'] == "mo_oidc_verify_customer") {
            $this->account($post);
        }
        //------------ HANDLE LOG OUT ACTION---------------
        elseif (isset($post['option']) and $post['option'] == 'logout') {
            $status = $this->removeCustomer();
            if ($status) {
                MoUtilities::showSuccessFlashMessage(Messages::ACCOUNT_REMOVED_SUCCESSFULLY);
                $this->view->assign('status', 'not_logged');
            } else {
                MoUtilities::showErrorFlashMessage('Some error occurred while logging you out.');
            }
        }

        //------------ CHANGING TABS---------------
        if (isset($post['option']) && !empty($this->tab))
            if ($post['option'] == 'oidc_settings' || $post['option'] == 'save_sp_settings' || $post['option'] == 'attribute_mapping' || $post['option'] == 'be_group_mapping' || $post['option'] == 'group_mapping' || $post['option'] == 'save_connector_settings' || $post['option'] == 'upload_metadata_file') {
                $this->tab = "Apps";
            } elseif ($post['option'] == 'apps') {
                $this->tab = "Apps";
            } elseif ($post['option'] == 'Twofa_Setting') {
                $this->tab = "Twofa_Setting";
            } elseif ($post['option'] == 'samloauthapplication') {
            } elseif ($post['option'] == 'attribute_mapping') {
                $this->tab = "Attribute_Mapping";
            } elseif ($post['option'] == 'be_group_mapping' || $post['option'] == 'group_mapping') {
                $this->tab = "Group_Mapping";
            } elseif ($post['option'] == 'mo_oidc_contact_us_query_option') {
                $this->tab = "Support";
            } elseif ($post['option'] == 'Upgrade') {
                $this->tab = "Upgrade";
            } elseif ($post['option'] == 'mo_support_submit') {
                // Keep the tab the support form was submitted from.
            } else {
                $this->tab = "Account";
            }

        $this->view->assign('helperDir', MoUtilities::getHelperDir());
       
        //------------ LOADING VARIABLES TO BE USED IN VIEW---------------
        $sms_email_detail = MoUtilities::get_sms_email_detail();
        $this->view->assign('sms_email_detail', $sms_email_detail);

        $regStatus = MoUtilities::fetchFromCustomer(Constants::CUSTOMER_REGSTATUS);
        $isLoggedIn = $regStatus == 'logged' || $regStatus == 'verified';
        // Treat legacy 'verified' status the same as logged-in for Free (account info display)
        if ($isLoggedIn) {
            $this->view->assign('log', '');
            $this->view->assign('status', 'logged');
            $this->view->assign('email', MoUtilities::fetchFromCustomer(Constants::CUSTOMER_EMAIL));
            $this->view->assign('key', MoUtilities::fetchFromCustomer(Constants::CUSTOMER_KEY));
            $this->view->assign('sms_email_detail', MoUtilities::get_sms_email_detail());
        } else {
            $this->view->assign('log', 'disabled');
            $this->view->assign('status', 'not_logged');
        }

        $transactionLimitExceeded = $this->isTransactionLimitExceeded();
        $this->view->assign('transactionLimitExceeded', $transactionLimitExceeded);
        $this->view->assign('ruleActionDisabled', ($transactionLimitExceeded || !$isLoggedIn) ? 'disabled' : '');

        $twofaRules = $this->getAll2faRules();

        // check if twofaRules is empty
        $checkTwofaRules = true;
        if(empty($twofaRules))
        {
            $checkTwofaRules = false;
        }

        $this->tab='Customer_Configuration';
        
        $this->view->assign('checkTwofaRules', $checkTwofaRules);

        $this->view->assign('twofaRules', $twofaRules);
        
        // Add 2FA users data for User Management tab
        $queryParams = $this->request->getQueryParams();
        $action = $queryParams['action'] ?? '';
        
        // Handle bulk actions
        if ($action === 'delete' && !empty($queryParams['selected_users'])) {
            $this->delete2faUsers(TypeAssert::positiveIntList($queryParams['selected_users']));
            MoUtilities::showSuccessFlashMessage('Selected users deleted successfully!');
            return $this->redirect('request');
        }

        if ($action === 'disable' && !empty($queryParams['selected_users'])) {
            $this->disable2faUsers(TypeAssert::positiveIntList($queryParams['selected_users']));
            MoUtilities::showSuccessFlashMessage('2FA disabled for selected users!');
            return $this->redirect('request');
        }

        if ($action === 'enable' && !empty($queryParams['selected_users'])) {
            $this->enable2faUsers(TypeAssert::positiveIntList($queryParams['selected_users']));
            MoUtilities::showSuccessFlashMessage('2FA enabled for selected users!');
            return $this->redirect('request');
        }
        
        // Get all 2FA users for client-side pagination
        $users = $this->get2faUsers('', PHP_INT_MAX, 0);

        $this->view->assign('users', $users);

        $this->view->assign('planName', '2FA Free');
        $this->view->assign('pluginVersion', MoUtilities::getExtensionVersion());

        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCaches();

        $typo3Version = MoUtilities::getTypo3Version();
        if ($typo3Version >= 12) {
            return $this->responseFactory->createResponse()
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->streamFactory->createStream(TypeAssert::htmlString($this->view->render())));
        }

        return $this->htmlResponse();
    }

    public function validateURL(string $url): int
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return 1;
        } else {
            return 0;
        }
    }

    public function support(): void
    {
        if (!$this->mo_is_curl_installed()) {
            MoUtilities::showErrorFlashMessage('ERROR: <a href="http://php.net/manual/en/curl.installation.php" 
                       target="_blank">PHP cURL extension</a> is not installed or disabled. Query submit failed.');
            return;
        }
        // Contact Us query
        $post = TypeAssert::stringKeyedArray($_POST);
        $email = TypeAssert::stringValue($post['mo_oidc_contact_us_email'] ?? null);
        $phone = TypeAssert::stringValue($post['mo_oidc_contact_us_phone'] ?? null);
        $query = TypeAssert::stringValue($post['mo_oidc_contact_us_query'] ?? null);

        $customer = new CustomerMo();

        if ($this->mo_check_empty_or_null($email) || $this->mo_check_empty_or_null($query)) {
            MoUtilities::showErrorFlashMessage('Please enter a valid Email address. ');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            MoUtilities::showErrorFlashMessage('Please enter a valid Email address. ');
        } else {
            $response = TypeAssert::decodeJsonArray($customer->submit_contact($email, $phone, $query));
            if (($response['status'] ?? null) === 'SUCCESS') {
                MoUtilities::showSuccessFlashMessage('Thanks for getting in touch! We shall get back to you shortly.');
            } else {
                MoUtilities::showSuccessFlashMessage('Thanks for getting in touch! We shall get back to you shortly.');
            }
        }
    }

    public function mo_is_curl_installed(): int
    {
        if (in_array('curl', get_loaded_extensions())) {
            return 1;
        } else {
            return 0;
        }
    }

    // FETCH OIDC VALUES

    public function mo_check_empty_or_null(mixed $value): bool
    {
        if (!isset($value) || empty($value)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $post
     */
    public function account(array $post): void
    {
        $email = TypeAssert::stringValue($post['email'] ?? null);
        $password = TypeAssert::stringValue($post['password'] ?? null);
        $customer = new CustomerMo();
        $customer->email = $email;

        $proxy_settings = json_encode([
            'proxy_host_name' => TypeAssert::stringValue($post['proxy_host_name'] ?? null),
            'port_number' => TypeAssert::stringValue($post['port_number'] ?? null),
            'proxy_username' => TypeAssert::stringValue($post['proxy_username'] ?? null),
            'proxy_password' => TypeAssert::stringValue($post['proxy_password'] ?? null),
        ]) ?: '{}';
        if (TypeAssert::stringValue($post['proxy'] ?? null) === 'isChecked') {
            MoUtilities::update_cust('proxy_needed', 1);
            MoUtilities::update_cust('proxy_settings', $proxy_settings);
        } else {
            MoUtilities::update_cust('proxy_needed', 0);
            MoUtilities::update_cust('proxy_settings', null);
        }

        MoUtilities::update_cust('cust_email', $email);
        $checkRaw = $customer->check_customer($email, $password);
        $check_content = TypeAssert::decodeJsonArray($checkRaw);

        if (($check_content['status'] ?? null) === 'CUSTOMER_NOT_FOUND') {
            $message = GeneralUtility::makeInstance(
                FlashMessage::class,
                'ERROR',
                $email . ' does not exist',
                ContextualFeedbackSeverity::ERROR,
                true
            );
            $out = GeneralUtility::makeInstance(ListRenderer::class)
                ->render([$message]);
            echo $out;
        } elseif (($check_content['status'] ?? null) === 'SUCCESS') {
            $key_content = TypeAssert::decodeJsonArray($customer->get_customer_key($email, $password));

            if (TypeAssert::stringValue($key_content['status'] ?? null) !== '') {
                MoUtilities::saveCustomer($key_content, $email);
                MoUtilities::showSuccessFlashMessage('User retrieved successfully.');
            } else {
                MoUtilities::showErrorFlashMessage('It seems like you have entered the incorrect password');
            }
        }
    }

    /**
     * This function handles the removal of miniOrange customer details.
     * @return bool
     */
    public function removeCustomer(): bool
    {
        MoUtilities::updateCustomer(Constants::CUSTOMER_KEY, '');
        MoUtilities::updateCustomer(Constants::CUSTOMER_EMAIL, '');
        MoUtilities::updateCustomer(Constants::CUSTOMER_TOKEN, '');
        MoUtilities::updateCustomer(Constants::CUSTOMER_API_KEY, '');
        MoUtilities::updateCustomer(Constants::CUSTOMER_REGSTATUS, '');
        MoUtilities::updateCustomer(Constants::CUSTOMER_OBJECT, '');
        MoUtilities::updateCustomer(Constants::CUSTOMER_CODE, '');

        return true;
    }

    //  SAVE CUSTOMER

    public function save(string $column, mixed $value, string $table): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $affectedRows = $queryBuilder->insert($table)->values([$column => $value,]);
        MoUtilities::executeAccordingToTypo3Version($affectedRows);
    }

    /**
     * Save Customer 2FA Rule to miniorange_tfa_settings table with duplicate rule validation
     */
    /**
     * @param array<string, mixed> $post
     */
    public function saveCustomer2faRuleAction(array $post): ResponseInterface
    {
        $site = $this->getMainWebsiteIdentifier();
        $methods = TypeAssert::stringListFromArray($post['methods'] ?? []);
        $customer2faEnabled = !empty($methods);

        $otpLength = TypeAssert::intValue($post['otpLength'] ?? null, 6);

        // Validate required fields
        if ($site === null) {
            MoUtilities::showErrorFlashMessage('No TYPO3 Main Website is configured.');
            $this->tab = "Twofa_Setting";
            $this->view->assign('tab', $this->tab);
            return $this->redirect('request');
        }

        if ($methods === []) {
            MoUtilities::showErrorFlashMessage('Please select at least one authentication method!');
            $this->tab = "Twofa_Setting";
            $this->view->assign('tab', $this->tab);
            return $this->redirect('request');
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_settings');

        $ruleId = TypeAssert::intValue($post['id'] ?? null);
        if ($ruleId > 0) {
            $existingRule = $connection->select(
                ['id', 'customer_2fa'],
                'miniorange_tfa_settings',
                ['id' => $ruleId]
            )->fetchAssociative();
            if (!$existingRule || empty($existingRule['customer_2fa'])) {
                MoUtilities::showErrorFlashMessage('Customer 2FA rule not found!');
                $this->tab = "Twofa_Setting";
                $this->view->assign('tab', $this->tab);
                return $this->redirect('request');
            }
        }

        $existing = $connection->select(['id'], 'miniorange_tfa_settings', [
            'customer_2fa_website_id' => $site,
            'customer_2fa' => 1
        ])->fetchAssociative();

        if ($existing && $ruleId !== TypeAssert::intValue($existing['id'] ?? null)) {
            MoUtilities::showErrorFlashMessage('A Customer 2FA rule for the Main Website already exists!');
            $this->tab = "Twofa_Setting";
            $this->view->assign('tab', $this->tab);
            return $this->redirect('request');
        }

        // Convert methods array to comma-separated string
        $methodsString = implode(',', $methods);

        $data = [
            'customer_2fa' => $customer2faEnabled ? 1 : 0,
            'customer_2fa_method' => $methodsString,
            'customer_2fa_group' => 'all', // Always set to 'all' since we're removing group-based logic
            'customer_2fa_website_id' => $site,
            'last_updated' => date('Y-m-d H:i:s'),
            'sms_configuration' => '',
            'email_configuration' => '',
            'otp_length' => $otpLength,
        ];

        // Handle update or insert. Posted site values are ignored; Main Website is always stored.
        if ($ruleId > 0) {
            $connection->update('miniorange_tfa_settings', $data, ['id' => $ruleId]);
        } else {
            // Creating new rule
            $data['installation_date'] = date('Y-m-d H:i:s');
            $connection->insert('miniorange_tfa_settings', $data);
        }

        MoUtilities::showSuccessFlashMessage('Customer 2FA rule saved successfully!');
        $this->tab = "Twofa_Setting";
        $this->view->assign('tab', $this->tab);
        return $this->redirect('request');
    }


    /**
     * Save Admin 2FA Rule to miniorange_tfa_settings table with enhanced validation
     */
    /**
     * @param array<string, mixed> $post
     */
    public function saveAdmin2faRuleAction(array $post): ResponseInterface
    {
        $site = 'all';
        $methods = TypeAssert::stringListFromArray($post['methods'] ?? []);
        $admin2faEnabled = !empty($methods);
        $otpLength = TypeAssert::intValue($post['otpLength'] ?? null, 6);

        if ($methods === []) {
            MoUtilities::showErrorFlashMessage('Please select at least one authentication method!');
            $this->tab = "Twofa_Setting";
            $this->view->assign('tab', $this->tab);
            return $this->redirect('request');
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_settings');

        // Admin 2FA is global: allow only one rule. When creating new, block if one already exists.
        if (TypeAssert::stringValue($post['id'] ?? null) === '') {
            $anyRulesExist = $connection->select(['id'], 'miniorange_tfa_settings', [
                'admin_2fa' => 1
            ])->fetchAssociative();

            if ($anyRulesExist) {
                MoUtilities::showErrorFlashMessage('An Admin 2FA rule already exists. Please edit the existing rule.');
                $this->tab = "Twofa_Setting";
                $this->view->assign('tab', $this->tab);
                return $this->redirect('request');
            }
        }

        // Convert methods array to comma-separated string
        $methodsString = implode(',', $methods);

        $data = [
            'admin_2fa' => $admin2faEnabled ? 1 : 0,
            'admin_2fa_method' => $methodsString,
            'admin_2fa_group' => 'all', // Always set to 'all' since we're removing group-based logic
            'admin_2fa_website_id' => $site,
            'last_updated' => date('Y-m-d H:i:s'),
            'sms_configuration' => '',
            'email_configuration' => '',
            'otp_length' => $otpLength,
        ];

        // Handle update or insert
        $existingId = TypeAssert::intValue($post['id'] ?? null);
        if ($existingId > 0) {
            $connection->update(
                'miniorange_tfa_settings',
                $data,
                ['id' => $existingId]
            );
        } else {
            // Creating new rule
            $data['installation_date'] = date('Y-m-d H:i:s');
            $connection->insert('miniorange_tfa_settings', $data);
        }

        MoUtilities::showSuccessFlashMessage('Admin 2FA rule saved successfully!');
        $this->tab = "Twofa_Setting";
        $this->view->assign('tab', $this->tab);
        return $this->redirect('request');
    }

    /**
     * Fetch all 2FA rules (both Customer and Admin) from miniorange_tfa_settings table
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function getAll2faRules(): array
    {
        try {
            $connection = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
                \TYPO3\CMS\Core\Database\ConnectionPool::class
            )->getConnectionForTable('miniorange_tfa_settings');

            // Fetch all records where either customer_2fa OR admin_2fa is enabled
            $queryBuilder = $connection->createQueryBuilder();
            $rules = $queryBuilder
                ->select('*')
                ->from('miniorange_tfa_settings')
                ->where(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('customer_2fa', 1),
                        $queryBuilder->expr()->eq('admin_2fa', 1)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable $e) {
            return [];
        }

        $formattedRules = [];

        foreach ($rules as $rule) {
            // Process CUSTOMER rules
            if (!empty($rule['customer_2fa'])) {
                $formattedRules[] = [
                    'id' => $rule['id'],
                    'type' => 'Customer',
                    'website' => 'Main Website',
                    'group' => $rule['customer_2fa_group'] ?? 'all',
                    'methods' => !empty($rule['customer_2fa_method']) && is_string($rule['customer_2fa_method'])
                        ? explode(',', $rule['customer_2fa_method']) : [],
                    'last_updated' => $rule['last_updated'] ?? '',
                ];
            }

            // Process ADMIN rules
            if (!empty($rule['admin_2fa'])) {
                $formattedRules[] = [
                    'id' => $rule['id'],
                    'type' => 'Admin',
                    'website' => $rule['admin_2fa_website_id'] ?? 'all',
                    'group' => $rule['admin_2fa_group'] ?? 'all',
                    'methods' => !empty($rule['admin_2fa_method']) && is_string($rule['admin_2fa_method'])
                        ? explode(',', $rule['admin_2fa_method']) : [],
                    'last_updated' => $rule['last_updated'] ?? '',
                ];
            }
        }

        return $formattedRules;
    }

    /**
     * Delete a 2FA rule by ID
     */
    /**
     * @param array<string, mixed> $post
     */
    public function delete2faRuleAction(array $post): ResponseInterface
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_settings');
        $connection->delete('miniorange_tfa_settings', ['id' => TypeAssert::intValue($post['rule_id'] ?? null)]);
        MoUtilities::showSuccessFlashMessage('Rule deleted successfully!'); 
        $this->tab = "Twofa_Setting";
        $this->view->assign('tab', $this->tab);
        return $this->redirect('request');
    }

    /**
     * @return array<string, mixed>|false
     */
    public function get2faRuleById(int $id): array|false
    {
        $connection = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Database\ConnectionPool::class
        )->getConnectionForTable('miniorange_tfa_settings');

        // Fetch both customer and admin columns
        $rule = $connection->select(
            [
                'id',
                'customer_2fa', 'customer_2fa_method', 'customer_2fa_group', 'customer_2fa_website_id',
                'admin_2fa', 'admin_2fa_method', 'admin_2fa_group', 'admin_2fa_website_id',
                'last_updated', 'otp_length'
            ],
            'miniorange_tfa_settings',
            ['id' => $id]
        )->fetchAssociative();

        if (is_array($rule)) {
            if (!empty($rule['customer_2fa'])) {
                $rule['methods'] = !empty($rule['customer_2fa_method']) && is_string($rule['customer_2fa_method'])
                    ? explode(',', $rule['customer_2fa_method']) : [];
                $rule['type'] = 'Customer';
                $rule['website'] = $rule['customer_2fa_website_id'];
                $rule['group'] = $rule['customer_2fa_group'];
            } elseif (!empty($rule['admin_2fa'])) {
                $rule['methods'] = !empty($rule['admin_2fa_method']) && is_string($rule['admin_2fa_method'])
                    ? explode(',', $rule['admin_2fa_method']) : [];
                $rule['type'] = 'Admin';
                $rule['website'] = $rule['admin_2fa_website_id'];
                $rule['group'] = $rule['admin_2fa_group'];
            } else {
                // fallback: treat as customer rule with empty methods
                $rule['methods'] = [];
                $rule['type'] = 'Customer';
                $rule['website'] = $rule['customer_2fa_website_id'] ?? '';
                $rule['group'] = $rule['customer_2fa_group'] ?? '';
            }
        }
        
        return $rule;
    }

    /**
     * Update 2FA Rule - Update only the 2FA methods for an existing rule
     */
    /**
     * @param array<string, mixed> $post
     */
    public function update2faRuleAction(array $post): ResponseInterface
    {
        $ruleId = TypeAssert::intValue($post['rule_id'] ?? null);
        $methods = TypeAssert::stringListFromArray($post['methods'] ?? []);
        // otp length
        $otpLength = TypeAssert::intValue($post['otpLength'] ?? null, 6);

        if (empty($methods) || empty($ruleId)) {
            MoUtilities::showErrorFlashMessage('No data provided for update!');
            $this->tab = "Twofa_Setting";
            $this->view->assign('tab', $this->tab);
            return $this->redirect('request');
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_settings');

        $existingRule = $connection->select(
            ['customer_2fa', 'admin_2fa'],
            'miniorange_tfa_settings',
            ['id' => $ruleId]
        )->fetchAssociative();

        if (!$existingRule) {
            MoUtilities::showErrorFlashMessage('2FA rule not found!');
            return $this->redirect('request');
        }

        // Prepare the update data
        $methodsString = implode(',', $methods);
        
        // Determine the rule type from storage, never from request data.
        if (!empty($existingRule['admin_2fa'])) {
            $ruleType = 'Admin';
            // Update admin 2FA methods
            $data = [
                'admin_2fa_method' => $methodsString,
                'last_updated' => date('Y-m-d H:i:s'),
                'otp_length' => $otpLength,
            ];
        } else {
            $mainWebsiteIdentifier = $this->getMainWebsiteIdentifier();
            if ($mainWebsiteIdentifier === null) {
                MoUtilities::showErrorFlashMessage('No TYPO3 Main Website is configured.');
                return $this->redirect('request');
            }

            $ruleType = 'Customer';
            // Update customer 2FA methods
            $data = [
                'customer_2fa_method' => $methodsString,
                'customer_2fa_website_id' => $mainWebsiteIdentifier,
                'last_updated' => date('Y-m-d H:i:s'),
                'otp_length' => $otpLength,
            ];
        }

        // Update the record
        try {
            $connection->update('miniorange_tfa_settings', $data, ['id' => $ruleId]);
        } catch (Exception $e) {
            MoUtilities::showErrorFlashMessage('Error updating 2FA rule: ' . $e->getMessage());
            $this->tab = "Twofa_Setting";
            $this->view->assign('tab', $this->tab);
            return $this->redirect('request');
        }

        MoUtilities::showSuccessFlashMessage('2FA rule updated successfully!');
        $this->tab = "Twofa_Setting";
        $this->view->assign('tab', $this->tab);
        return $this->redirect('request');
    }

    private function getMainWebsiteIdentifier(): ?string
    {
        return $this->siteRepository->findMainIdentifier();
    }



    /**
     * Get 2FA users with pagination and search
     *
     * @return list<array<string, mixed>>
     */
    private function get2faUsers(string $search = '', int $limit = 10, int $offset = 0): array
    {
        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('miniorange_tfa_users');

            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->select('*')
                ->from('miniorange_tfa_users')
                ->orderBy('id', 'ASC')
                ->setMaxResults($limit)
                ->setFirstResult($offset);

            // Add search condition if search term is provided
            if (!empty($search)) {
                $queryBuilder->where(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->like('email', $queryBuilder->createNamedParameter('%' . $search . '%')),
                        $queryBuilder->expr()->like('username', $queryBuilder->createNamedParameter('%' . $search . '%')),
                        $queryBuilder->expr()->like('phone', $queryBuilder->createNamedParameter('%' . $search . '%'))
                    )
                );
            }

            $result = $queryBuilder->executeQuery();
        } catch (\Throwable $e) {
            return [];
        }

        $users = [];

        while ($row = $result->fetchAssociative()) {
            // Determine website/role based on website_id
            $website = $this->getWebsiteName(TypeAssert::stringValue($row['website_id'] ?? null));
            $role = $row['is_admin'] ? 'Admin' : 'Customer';
            
            // Determine 2FA status
            $status = $row['disable_2fa'] ? 'Disabled' : 'Enabled';
            
            // Format 2FA method for display
            $method = $this->format2faMethod(TypeAssert::stringValue($row['active_method'] ?? null));
            
            $users[] = [
                'id' => TypeAssert::intValue($row['id'] ?? null),
                'email' => TypeAssert::stringValue($row['email'] ?? null),
                'username' => TypeAssert::stringValue($row['username'] ?? null),
                'phone' => TypeAssert::stringValue($row['phone'] ?? null),
                'website' => $website,
                'role' => $role,
                'method' => $method,
                'status' => $status,
                'active_method' => TypeAssert::stringValue($row['active_method'] ?? null),
                'disable_2fa' => TypeAssert::intValue($row['disable_2fa'] ?? null)
            ];
        }

        return $users;
    }

    /**
     * Get total count of 2FA users
     */
    private function get2faUsersCount(string $search = ''): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_users');

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('id')
            ->from('miniorange_tfa_users');

        // Add search condition if search term is provided
        if (!empty($search)) {
            $queryBuilder->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('email', $queryBuilder->createNamedParameter('%' . $search . '%')),
                    $queryBuilder->expr()->like('username', $queryBuilder->createNamedParameter('%' . $search . '%')),
                    $queryBuilder->expr()->like('phone', $queryBuilder->createNamedParameter('%' . $search . '%'))
                )
            );
        }

        $result = $queryBuilder->executeQuery();
        $count = 0;
        while ($result->fetchAssociative()) {
            $count++;
        }

        return $count;
    }

    /**
     * Get website name from website_id
     */
    private function getWebsiteName(string $websiteId): string
    {
        if (empty($websiteId)) {
            return 'Backend';
        }
        
        // Try to get site name from TYPO3 sites configuration
        try {
            $siteFinder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
            $sites = $siteFinder->getAllSites();
            
            foreach ($sites as $site) {
                if ($site->getIdentifier() === $websiteId) {
                    return TypeAssert::stringValue(
                        $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                        $websiteId
                    );
                }
            }
        } catch (Exception $e) {
            // Fallback to website_id if site not found
        }
        
        return $websiteId;
    }

    /**
     * Format 2FA method for display
     */
    private function format2faMethod(string $method): string
    {
        switch (strtolower($method)) {
            case 'email':
                return 'OTP Over Email';
            case 'sms':
                return 'OTP Over SMS';
            case 'sms_email':
                return 'OTP Over SMS & Email';
            case 'google':
                return 'Google Authenticator';
            case 'microsoft':
                return 'Microsoft Authenticator';
            default:
                return ucfirst($method);
        }
    }

    /**
     * Delete 2FA users
     */
    /**
     * @param list<int> $userIds
     */
    private function delete2faUsers(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }
        
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_users');
        
        $connection->delete(
            'miniorange_tfa_users',
            ['id' => $userIds]
        );
    }

    /**
     * Disable 2FA for users
     *
     * @param list<int> $userIds
     */
    private function disable2faUsers(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }
        
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_users');
        
        $connection->update(
            'miniorange_tfa_users',
            ['disable_2fa' => 1],
            ['id' => $userIds]
        );
    }

    /**
     * Enable 2FA for users
     *
     * @param list<int> $userIds
     */
    private function enable2faUsers(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }
        
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('miniorange_tfa_users');
        
        $connection->update(
            'miniorange_tfa_users',
            ['disable_2fa' => 0],
            ['id' => $userIds]
        );
    }

    /**
     * Reset 2FA for one or more users
     * 
     * This will remove all users whose IDs are provided in the POST response (either as 'user_id' or 'user_ids').
     */
    /**
     * @param array<string, mixed> $post
     */
    public function reset2faUserAction(array $post): ResponseInterface
    {
        $userIds = TypeAssert::positiveIntList($post['user_ids'] ?? $post['user_id'] ?? null);

        if (!empty($userIds)) {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('miniorange_tfa_users');

            // Use QueryBuilder for proper IN clause handling
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->delete('miniorange_tfa_users')
                ->where(
                    $queryBuilder->expr()->in('id', $queryBuilder->createNamedParameter($userIds, Connection::PARAM_INT_ARRAY))
                );

            $queryBuilder->executeStatement();

            if (count($userIds) > 1) {
                MoUtilities::showSuccessFlashMessage('2FA reset successfully for the selected users!');
            } else {
                MoUtilities::showSuccessFlashMessage('2FA reset successfully for the user!');
            }
        } else {
            MoUtilities::showErrorFlashMessage('Invalid user ID(s)!');
            $this->tab = "Twofa_Setting";
            $this->view->assign('tab', $this->tab);
            return $this->redirect('request');
        }

        // Yes, this will remove all users whose IDs are provided in the POST response.
        return $this->redirect('request');
    }

    /**
     * Disable 2FA for one or more users
     * 
     * This will set disable_2fa = 1 for all users whose IDs are provided in the POST response.
     */
    /**
     * @param array<string, mixed> $post
     */
    public function disable2faUserAction(array $post): ResponseInterface
    {
        $userIds = TypeAssert::positiveIntList($post['user_ids'] ?? $post['user_id'] ?? null);

        if (!empty($userIds)) {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('miniorange_tfa_users');

            // Use QueryBuilder for proper IN clause handling
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->update('miniorange_tfa_users')
                ->set('disable_2fa', 1)
                ->where(
                    $queryBuilder->expr()->in('id', $queryBuilder->createNamedParameter($userIds, Connection::PARAM_INT_ARRAY))
                );

            $queryBuilder->executeStatement();

            if (count($userIds) > 1) {
                MoUtilities::showSuccessFlashMessage('2FA disabled successfully for the selected users!');
            } else {
                MoUtilities::showSuccessFlashMessage('2FA disabled successfully for the user!');
            }
        } else {
            MoUtilities::showErrorFlashMessage('Invalid user ID(s)!');
        }

        return $this->redirect('request');
    }

    /**
     * Display user management interface
     */
    public function usersAction(): ResponseInterface
    {
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('@miniorange/mfa/Resources/Public/Css/otp/bootstrap.min.css');
        $pageRenderer->addCssFile('@miniorange/mfa/Resources/Public/Css/otp/font-awesome.min.css');
        $pageRenderer->addCssFile('@miniorange/mfa/Resources/Public/Css/otp/main.css');
        $pageRenderer->loadJavaScriptModule('@miniorange/mfa/jquery.min.js');
        $pageRenderer->loadJavaScriptModule('@miniorange/mfa/bootstrap.min.js');
        $pageRenderer->loadJavaScriptModule('@miniorange/mfa/main.js');

        // Get query parameters for pagination and search
        $queryParams = $this->request->getQueryParams();
        $search = TypeAssert::stringValue($queryParams['search'] ?? null);
        $page = max(1, TypeAssert::intValue($queryParams['page'] ?? null, 1));
        $limit = TypeAssert::intValue($queryParams['limit'] ?? null, 10);
        if ($limit < 1) {
            $limit = 10;
        }
        $offset = ($page - 1) * $limit;

        // Get users and total count
        $users = $this->get2faUsers($search, $limit, $offset);
        $totalUsers = $this->get2faUsersCount($search);
        $totalPages = ceil($totalUsers / $limit);

        // Calculate pagination values
        $startEntry = ($page - 1) * $limit + 1;
        $endEntry = min($page * $limit, $totalUsers);
        
        // Generate page numbers for pagination (show max 5 pages around current)
        $pageNumbers = [];
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++) {
            $pageNumbers[] = $i;
        }

        // Assign data to view
        $this->view->assign('users', $users);
        $this->view->assign('search', $search);
        $this->view->assign('currentPage', $page);
        $this->view->assign('totalPages', $totalPages);
        $this->view->assign('totalUsers', $totalUsers);
        $this->view->assign('limit', $limit);
        $this->view->assign('startEntry', $startEntry);
        $this->view->assign('endEntry', $endEntry);
        $this->view->assign('pageNumbers', $pageNumbers);
        $this->view->assign('tab', 'User_Management');

        $typo3Version = MoUtilities::getTypo3Version();
        if ($typo3Version >= 12) {
            return $this->responseFactory->createResponse()
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->streamFactory->createStream(TypeAssert::htmlString($this->view->render())));
        }

        return $this->htmlResponse();
    }

    // Bulk action methods removed - now handled by requestAction with bulk_reset_2fa and bulk_disable_2fa options

    /**
     * Handle support form submission
     *
     * @param array<string, mixed> $postData
     * @return array{status: string, message: string}
     */
    private function handleSupportFormSubmission(array $postData): array
    {
        $successMessage = 'Thanks for getting in touch! We shall get back to you shortly.If you dont hear from us within 24hours, please send a follow up mail to magentosupport@xecurify.com';

        try {
            $email = TypeAssert::stringValue($postData['email'] ?? null);
            $phone = TypeAssert::stringValue($postData['phone'] ?? null);
            $query = TypeAssert::stringValue($postData['query'] ?? null);

            error_log('Support form data - Email: ' . $email . ', Phone: ' . $phone . ', Query: ' . $query);

            if ($email === '' || $query === '') {
                return ['status' => 'error', 'message' => 'Please fill in all required fields.'];
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['status' => 'error', 'message' => 'Please enter a valid email address.'];
            }

            if (!$this->mo_is_curl_installed()) {
                return [
                    'status' => 'error',
                    'message' => 'ERROR: PHP cURL extension is not installed or disabled. Query submit failed.',
                ];
            }

            $customer = new CustomerMo();
            $apiResponse = $customer->submit_contact($email, $phone, $query);

            error_log('CustomerMo response: ' . print_r($apiResponse, true));

            return ['status' => 'success', 'message' => $successMessage];
        } catch (\Exception $e) {
            error_log('Support form error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => 'Something went wrong. Please try again.'];
        }
    }

    /**
     * AJAX action to handle support form submission
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function supportAjaxAction(): ResponseInterface
    {
        $response = [
            'status' => 'success',
            'message' => 'Thanks for getting in touch! We shall get back to you shortly.If you dont hear from us within 24hours, please send a follow up mail to magentosupport@xecurify.com',
        ];

        try {
            if ($this->request->getHeaderLine('X-Requested-With') !== 'XMLHttpRequest') {
                $response['message'] = 'Invalid request method';

                return $this->sendJsonResponse($response);
            }

            $postData = TypeAssert::parsedBodyArray($this->request->getParsedBody());
            if ($postData === []) {
                $response['message'] = 'No data received';

                return $this->sendJsonResponse($response);
            }

            $email = TypeAssert::stringValue($postData['email'] ?? null);
            $phone = TypeAssert::stringValue($postData['phone'] ?? null);
            $query = TypeAssert::stringValue($postData['query'] ?? null);

            if ($email === '' || $query === '') {
                $response['message'] = 'Please fill in all required fields.';

                return $this->sendJsonResponse($response);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response['message'] = 'Please enter a valid email address.';

                return $this->sendJsonResponse($response);
            }

            if (!$this->mo_is_curl_installed()) {
                $response['message'] = 'ERROR: PHP cURL extension is not installed or disabled. Query submit failed.';

                return $this->sendJsonResponse($response);
            }

            $customer = new CustomerMo();
            $apiResponse = TypeAssert::decodeJsonArray($customer->submit_contact($email, $phone, $query));
            if (($apiResponse['status'] ?? null) === 'SUCCESS') {
                $response['status'] = 'success';
                $response['message'] = 'Thanks for getting in touch! We shall get back to you shortly.If you dont hear from us within 24hours, please send a follow up mail to magentosupport@xecurify.com';
            } else {
                $response['status'] = 'success';
                $response['message'] = 'Thanks for getting in touch! We shall get back to you shortly.If you dont hear from us within 24hours, please send a follow up mail to magentosupport@xecurify.com';
            }
        } catch (\Exception $e) {
            $response['message'] = 'An error occurred: ' . $e->getMessage();
        }

        return $this->sendJsonResponse($response);
    }

    private function isXmlHttpRequest(): bool
    {
        if (strtolower($this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json');
    }

    /**
     * Helper method to return JSON response
     *
     * @param array<string, mixed> $data
     */
    private function sendJsonResponse(array $data): ResponseInterface
    {
        $typo3Version = MoUtilities::getTypo3Version();
        
        if ($typo3Version >= 12) {
            return $this->responseFactory->createResponse()
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($data) ?: '{}'));
        } else {
            header('Content-Type: application/json');
            echo json_encode($data);
            exit;
        }
    }

    /**
     * function to update the customer
     *
     */
    public function update_cust(string $column, mixed $value): void
    {
        try {
            if (MoUtilities::fetch_cust('id') == null) {
                $this->insertValue();
            }
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('miniorange_tfa_customer');
            if (is_array($value)) {
                $encoded = json_encode($value);
                $value = $encoded !== false ? $encoded : '{}';
            }
            $queryBuilder->update('miniorange_tfa_customer')
                ->where((string)$queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
                ->set($column, $value)
                ->executeStatement();
        } catch (\Throwable $e) {
            // Table may not exist yet – skip update
        }
    }

    /**
     * function to insert the value
     *
     */
    public function insertValue(): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('miniorange_tfa_customer');
        $queryBuilder->insert('miniorange_tfa_customer')->values(['id' => '1'])->executeStatement();
    }

    /**
     * Whether the free-plan 2FA transaction/user cap has been reached.
     */
    private function isTransactionLimitExceeded(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(Constants::TABLE_CUSTOMER);
        $limiter = GeneralUtility::makeInstance(AuthenticatorEnrollmentLimiter::class);

        return $limiter->isTwoFactorLimitReached($connection);
    }
}
