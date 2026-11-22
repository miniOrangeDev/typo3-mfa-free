<?php

namespace Miniorange\Otp\Helper;

class Constants
{

    //images
    const IMAGE_RIGHT = 'right.png';
    const IMAGE_WRONG = 'wrong.png';

    //MINIORANGE API URLs
    const HOSTNAME = "https://login.xecurify.com";
    const APPLICATION_NAME = 'typo3_2fa_free_plan';

    //TABLE NAMES
    const TABLE_CUSTOMER = 'miniorange_tfa_customer';
    const TABLE_FE_USERS = 'fe_users';
    const TABLE_FE_SESSIONS = 'fe_sessions';
    const TABLE_FE_GROUPS = 'fe_groups';
    const TABLE_BE_USERS = 'be_users';
    const TABLE_BE_GROUPS = 'be_groups';
    const TABLE_TFA_USERS = 'miniorange_tfa_users';

    // COLUMNS IN CUSTOMER_TABLE
    const CUSTOMER_EMAIL = "cust_email";
    const CUSTOMER_KEY = "cust_key";
    const CUSTOMER_API_KEY = "cust_api_key";
    const CUSTOMER_TOKEN = "cust_token";
    const CUSTOMER_REGSTATUS = "cust_reg_status";
    const CUSTOMER_CODE = "cust_code";
    const CUSTOMER_OBJECT = "cust_object";
    const CUSTOMER_USER_COUNT = "user_count";
   
    //OTHER CONSTANTS
    const EXISTING_USERS_ONLY = 'existing_users_only';
    const AREA_OF_INTEREST = "TYPO3 2FA Extension";
    const RESOURCE_FOLDER = 'resources';
    const EXTENSION_KEY = '2fa_typo3';
    const TWO_FACTOR_USER_LIMIT = 10;

}