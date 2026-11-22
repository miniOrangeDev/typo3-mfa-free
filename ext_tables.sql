CREATE TABLE miniorange_tfa_customer
(
    id                        int(11) NOT NULL auto_increment,
    cust_email                varchar(100) DEFAULT '',
    cust_key                  varchar(100) DEFAULT '',
    cust_api_key              varchar(100) DEFAULT '',
    cust_token                varchar(100) DEFAULT '',
    cust_reg_status           varchar(100) DEFAULT '',
    cust_object               text         DEFAULT '',
    cust_code                 varchar(100) DEFAULT '',
    proxy_needed              binary       DEFAULT 0,
    proxy_settings            text         DEFAULT '',
    user_count                text         DEFAULT '',
    PRIMARY KEY (id)
);

CREATE TABLE miniorange_tfa_users
(
    id                          int(11) NOT NULL auto_increment COMMENT 'ID',
    username                    varchar(255) NOT NULL COMMENT 'Username',
    email                       varchar(255) COMMENT 'Email',
    phone                       varchar(255) COMMENT 'Phone number',
    active_method               varchar(255) COMMENT 'Active Method',
    secret                      varchar(255) COMMENT 'Authenticator Secret',
    transactionId               varchar(255) COMMENT 'Transaction ID',
    kba_method                  varchar(1000) COMMENT 'KBA Method',
    website_id                  varchar(255) COMMENT 'Website ID',
    skip_twofa                  varchar(255) COMMENT 'Skip TwoFA',
    skip_twofa_configured_date  varchar(255) COMMENT 'Skip TwoFA information',
    skip_twofa_premanent        tinyint(1) DEFAULT 0 COMMENT 'Customer Skip TwoFA',
    device_info                 text COMMENT 'Device Information',
    disable_2fa                 tinyint(1) DEFAULT 0 COMMENT 'Disable 2fa',
    ip_white                    varchar(255) COMMENT 'IP White',
    is_admin                    tinyint(1) DEFAULT 0 COMMENT 'Is Admin',
    PRIMARY KEY (id)
);

CREATE TABLE miniorange_tfa_settings
(
    id                        int(11) NOT NULL auto_increment,
    otp_length                int(11) DEFAULT 6 COMMENT 'OTP Length',
    sms_gateway_enabled       tinyint(1) DEFAULT 0 COMMENT 'Enable SMS Gateway',
    sms_configuration         text COMMENT 'SMS Gateway Configuration (JSON or serialized)',
    email_gateway_enabled     tinyint(1) DEFAULT 0 COMMENT 'Enable Email Gateway',
    email_configuration       text COMMENT 'Email Gateway Configuration (JSON or serialized)',
    customer_2fa              tinyint(1) DEFAULT 0 COMMENT 'Enable 2FA for Customers',
    customer_2fa_method       varchar(255) COMMENT '2FA Methods for Customers (comma-separated or JSON)',
    admin_2fa                 tinyint(1) DEFAULT 0 COMMENT 'Enable 2FA for Admins',
    admin_2fa_method          varchar(255) COMMENT '2FA Methods for Admins (comma-separated or JSON)',
    customer_2fa_group        varchar(255) COMMENT 'Customer 2FA Group',
    admin_2fa_group           varchar(255) COMMENT 'Admin 2FA Group',
    customer_2fa_website_id   varchar(255) COMMENT 'Customer 2FA Website ID',
    admin_2fa_website_id      varchar(255) COMMENT 'Admin 2FA Website ID',
    frontend_user_methods     varchar(255) COMMENT 'Frontend User 2FA Methods (comma-separated or JSON)',
    installation_date         datetime COMMENT 'Installation Date',
    last_updated              datetime COMMENT 'Last Updated',
    PRIMARY KEY (id)
);