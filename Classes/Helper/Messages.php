<?php


namespace Miniorange\Otp\Helper;

class Messages
{
    //General Flow Messages
    const ERROR_OCCURRED = 'An error occured while processing your request. Please try again.';

    //cURL Error
    const CURL_ERROR = 'ERROR: <a href="http://php.net/manual/en/curl.installation.php" target="_blank">PHP cURL extension</a> 
                                            is not installed or disabled. Query submit failed.';

    //Save Settings Error
    const ISSUER_EXISTS = 'You seem to already have an Identity Provider for that issuer configured under : <i>{{name}}</i>';
    const NO_IDP_CONFIG = 'Please check and make sure your Plugin Settings are configured properly.';

    const SETTINGS_SAVED = 'Settings saved successfully.';


    //Save Settings Error 
    const SAML_SETTINGS_SAVED = 'Settings saved successfully.';
    const IDP_DELETED = 'Identity Provider settings deleted successfully.';
    const SP_ENTITY_ID_CHANGED = 'SP Entity ID changed successfully.';
    const SP_ENTITY_ID_NULL = 'SP EntityID/Issuer cannot be NULL.';
    const SUPPORT_QUERY_SENT = 'Support query sent ! We will get in touch with you shortly.';
    const COULD_NOT_SEND_QUERY = 'could not send query. Please try again later or mail us at info@miniorange.com';
    const USER_RETRIEVED = 'User retrieved successfully.';
    const INCORRECT_PASSWORD = 'It seems like you have entered the incorrect password';
    const ATTRIBUTE_MAPPING_SAVED = 'Attributes Mappings saved successfully.';
    const CUSTOM_ATTRIBUTE_MAPPING_SAVED = 'Custom Attributes Mappings saved successfully.';
    const GROUP_MAPPING_SAVED = 'Group Mappings saved successfully.';
    const ACCOUNT_REMOVED_SUCCESSFULLY = 'Your account has been removed successfully.';
    const PROVIDER_LIMIT_EXCEEDED = 'Provider limit exceeded';
    const ENTER_IDP_NAME = 'Please enter Identity Provider Name';
    const FILL_ALL_THE_NECESSARY_FIELDS_FOR_METADATA = 'Fill all the fields to download the metadata file';
    const INCORRECT_CERT_FORMAT = 'Incorrect certificate format';
    const PROVIDE_PROPER_VALUES = 'Please provide proper values.';
    const FILL_ALL_REQUIRED_FIELDS = 'Please fill all the required fields';
    const NO_METADATA_PROVIDED = 'No Metadata File/URL Provided.';
    const INVALID_IDP_METADATA = 'Invalid IDP Metadata!';
    const PROVIDE_VALID_METADATA = 'Please provide valid metadata.';
    const QUERY_SUBMIT_FAILED = 'ERROR: <a href="http://php.net/manual/en/curl.installation.php" target="_blank">PHP cURL extension</a> is not installed or disabled. Query submit failed.';
    const PROVIDE_VALID_EMAIL = 'Please enter a valid Email address. ';
    const NO_ATTRIBUTES_RECEIVED = 'Attributes not received. Unable to login';
    const INVALID_CUSTOM_ATTRIBUTE_MAPPING = 'Invalid custom attribute mapping found!.';


    //SAML SSO Error Messages
    const INVALID_INSTANT = '<strong>INVALID_REQUEST: </strong>Request time is greater than the current time.<br/>';
    const INVALID_SAML_VERSION = 'We only support SAML 2.0! Please send a SAML 2.0 request.<br/>';
    const INVALID_IDP = '<strong>INVALID_IDP: </strong>No Identity Provider configuration found. Please configure your 
                                             Identity Provider.<br/>';
    const INVALID_RESPONSE_SIGNATURE = '<strong>INVALID_SIGNATURE: </strong>Invalid Signature. Please check your certificates.';
    const SAML_INVALID_OPERATION = '<strong>INVALID_OPERATION: </strong>Invalid Operation! Please contact your site administrator.<br/>';
    const MISSING_NAMEID = 'Missing <saml:NameID> or <saml:EncryptedID> in <saml:Subject>.';
    const INVALID_NO_OF_NAMEIDS = 'More than one <saml:NameID> or <saml:EncryptedD> in <saml:Subject>.';
    const MISSING_ID_FROM_RESPONSE = 'Missing ID attribute on SAML assertion.';
    const MISSING_ISSUER_VALUE = 'Missing <saml:Issuer> in assertion.';
    const INVALID_ISSUER = 'Issuer cannot be verified. Expected {{expect}}, found {{found}}';
    const INVALID_AUDIENCE = 'Invalid audience URI. Expected {{expect}}, found {{found}}';
    const INVALID_DESTINATION = 'Destination in response doesn\'t match the current URL. Destination is {{destination}}, 
                                             current URL is {{currenturl}}.';
    const MISSING_ATTRIBUTES_EXCEPTION = 'SAML Response doesn\'t have the necessary attributes to log the user in';
    const INVALID_STATUS_CODE = '<strong>INVALID_STATUS_CODE: </strong> The Identity Provider returned an Invalid response. 
                                             Identity Provider has sent {{statuscode}} status code in SAML Response.
                                             Please check with your Identity Provider for more information.';
    const MISSING_SAML_RESPONSE = 'No Valid Response Found';
    const SAML_RESPONSE = "<pre>{{xml}}</pre>";
    const FORMATTED_CERT = "<pre>{{cert}}</pre>";
    const INVALID_CERTIFICATE_FORMAT = "The x509 certificate configured in the connector is not in the correct format";
    const REQUIRED_FIELDS = 'Please fill all the required fields';


    /**
     * @param array<string, mixed> $data
     */
    public static function parse(string $message, array $data = []): string
    {
        $resolved = constant('self::' . $message);
        if (!is_string($resolved)) {
            return '';
        }
        foreach ($data as $key => $value) {
            $resolved = str_replace('{{' . $key . '}}', TypeAssert::stringValue($value), $resolved);
        }
        return $resolved;
    }
}