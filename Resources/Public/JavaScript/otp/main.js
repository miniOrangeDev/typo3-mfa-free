document.addEventListener("DOMContentLoaded", function () {
    // Initialize user management search functionality
    initializeUserManagementSearch();
    
    const tabyInput = document.getElementById('taby');
    const session = tabyInput ? tabyInput.value : '';

    const validTabs = [
        "OIDC_Settings", "Identity_Provider", "Apps",
        "Attribute_Mapping", "Group_Mapping", "Support",
        "Upgrade", "Upgrade_Manager", "Customer_Configuration", "Admin_Configuration", "Account", "Twofa_Setting","Custom_Gateway","User_Management","Twofa_Setting_Choose"
    ];

    const tabToActivate = validTabs.includes(session) ? session : "Account";
    openTab(tabToActivate);

    const btnId = `${tabToActivate.toLowerCase()}_tab_btn`;
    const btnEl = document.getElementById(btnId);
    if (btnEl) btnEl.classList.add("active");

    const sessionTab = document.getElementById(tabToActivate);
    if (sessionTab && !sessionTab.classList.contains("active")) {
        sessionTab.classList.add("active");
    }

    // Handle tab button clicks
    document.querySelectorAll(".tablinks.button").forEach(button => {
        button.addEventListener("click", () => {
            removeFlashMessage();
            const tabName = button.id.replace('_Tab', '');
            openTab(tabName);
        });
    });

    // Handle application card clicks
    document.querySelectorAll(".selection-card").forEach(card => {
        card.addEventListener("click", () => {
            removeFlashMessage();
            const tabName = card.getAttribute("data-tab");
            openTab(tabName);
        });
    });

    // Handle form submit
    const submitBtn = document.getElementById("submitForms");
    if (submitBtn) {
        submitBtn.addEventListener("click", function () {
            const formData1 = getFormData("gm_form1");
            const formData2 = getFormData("gm_form2");

            const mergedFormData = {
                form1: formData1,
                form2: formData2
            };
        });
    }

    // Initialize proxy functionality
    initializeProxy();

    // Initialize SP settings form submission
    const spSettingsForm = document.getElementById('sp_setting_form');
    if (spSettingsForm) {
        spSettingsForm.addEventListener('click', function(e) {
            e.preventDefault();
            save_sp_data();
        });
    }

    // Initialize download metadata button
    const downloadMetadataBtn = document.getElementById('download_metadata');
    if (downloadMetadataBtn) {
        downloadMetadataBtn.addEventListener('click', function(e) {
            e.preventDefault();
            set_value();
        });
    }

    // Initialize submit upload metadata button
    const submitUploadBtn = document.getElementById('submit_upload_metadata');
    if (submitUploadBtn) {
        submitUploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            uploadFile();
        });
    }
    
    // Initialize cancel button
    const cancelBtn = document.getElementById('cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function(e) {
            e.preventDefault();
            cancel();
        });
    }

    // Initialize test configuration button
    const testConfigBtn = document.getElementById('test_config');
    if (testConfigBtn) {
        testConfigBtn.addEventListener('click', function(e) {
            e.preventDefault();
            set_test_config_value();
        });
    }

    // Initialize custom attribute mapping form submission
    const customAttrFormBtn = document.getElementById('submit_custom_attr_btn');
    if (customAttrFormBtn) {
        customAttrFormBtn.addEventListener('click', function(e) {
            e.preventDefault();
            jQuery('#custom_attrs_form').submit();
        });
    }

    // Initialize attribute mapping form submission
    const attrMappingBtn = document.getElementById('custom_attrs');
    if (attrMappingBtn) {
        attrMappingBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = document.getElementById('attrs_form');
            if (form) {
                // Validate required fields
                const usernameField = document.getElementById('am_username');
                if (!usernameField || !usernameField.value.trim()) {
                    alert('Username field is required');
                    return;
                }

                // Submit the form using jQuery to ensure proper form submission
                jQuery('#attrs_form').submit();
            }
        });
    }

    // Initialize add custom attribute button
    const addCustomAttrBtn = document.getElementById('add_custom_attr');
    if (addCustomAttrBtn) {
        addCustomAttrBtn.addEventListener('click', function(e) {
            e.preventDefault();
            addCustomAttribute();
        });
    }

    // Initialize delete custom attribute button
    const deleteCustomAttrBtn = document.getElementById('delete_custom_attr');
    if (deleteCustomAttrBtn) {
        deleteCustomAttrBtn.addEventListener('click', function(e) {
            e.preventDefault();
            deleteCustomAttribute();
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Handle menu toggling
    document.addEventListener('click', function(e) {
        // Toggle menu visibility
        if (e.target.classList.contains('action-menu-btn')) {
            const container = e.target.closest('.action-menu-container');
            document.querySelectorAll('.action-menu-container').forEach(menu => {
                if (menu !== container) menu.classList.remove('active');
            });
            container.classList.toggle('active');
        }
        
        // Close menus when clicking outside
        if (!e.target.closest('.action-menu-container')) {
            document.querySelectorAll('.action-menu-container').forEach(menu => {
                menu.classList.remove('active');
            });
        }
    });
});


function getFormData(formId) {
    const formData = {};
    const form = document.getElementById(formId);
    if (!form) return formData;

    const inputs = form.querySelectorAll("input, select");
    inputs.forEach(input => {
        formData[input.name] = input.value;
    });

    return formData;
}

function save_sp_data() {
    jQuery('#metadata_download').val('save_sp_settings');
    jQuery('#sp_settings').submit();
}

function set_value() {
    jQuery('#metadata_download').val('mosaml_metadata_download');
    jQuery('#sp_settings').submit();
}

function set_sp_cert_value() {
    jQuery('#metadata_download').val('mosaml_sp_cert_download');
    jQuery('#sp_settings').submit();
}

function set_test_config_value() {
    const appName = jQuery("#app_name").val();
    const fesaml = jQuery("#fesaml").val();
    
    if (!appName || !fesaml) {
        return;
    }
    
    const cleanedUrl = fesaml.replace(/\s+/g, '');
    const testUrl = `${cleanedUrl}/?RelayState=testconfig&app=${appName}`;
    
    
    const myWindow = window.open(
        testUrl,
        "Test Attribute Configuration",
        "width=600,height=600"
    );
    
    if (myWindow) {
        myWindow.focus();
    } else {
    }
}

function mo_saml_valid_query(f) {
    f.value = f.value.replace(/[^a-zA-Z?,.\(\)\/@ 0-9]/g, '');
}

function removeFlashMessage() {
    document.querySelectorAll('.typo3-messages').forEach(el => el.remove());
}

function openTab(activeTab) {
    const leftContainer = document.getElementById("leftContainer");
    if (leftContainer) {
        leftContainer.classList.add("showElement");
        leftContainer.classList.remove("hideElement");
    }

    const tabcontent = document.getElementsByClassName("tabcontent");
    for (let i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }

    const tablinks = document.getElementsByClassName("tablinks");
    for (let i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }

    const supportTab = document.getElementById("Support");
    const userAttrDiv = document.getElementById("userAttrDiv");

    if (activeTab === "Support") {
        if (userAttrDiv) userAttrDiv.classList.replace("showElement", "hideElement");
        if (supportTab) supportTab.style.display = "block";
        if (leftContainer) leftContainer.classList.replace("showElement", "hideElement");
        const supportTabBtn = document.getElementById("Support_Tab");
        if (supportTabBtn) supportTabBtn.classList.add("active");
    } else {
        if (userAttrDiv) {
            if (activeTab === "Attribute_Mapping") {
                userAttrDiv.classList.replace("hideElement", "showElement");
            } else {
                userAttrDiv.classList.replace("showElement", "hideElement");
            }
        }

        const tabPanel = document.getElementById(activeTab);
        if (tabPanel) tabPanel.style.display = "block";

        if (supportTab && !(activeTab === "Apps" || activeTab === "Upgrade")) {
            supportTab.style.display = "block";
        }

        // Modified logic to handle Twofa_Setting tab and its sub-sections
        let tabBtn;
        if (["Twofa_Setting", "Twofa_Configuration", "Customer_Configuration", "Admin_Configuration","Twofa_Setting_Choose"].includes(activeTab)) {
            // For Twofa_Setting and its sub-sections, activate the Twofa_Setting_Tab
            tabBtn = document.getElementById("Twofa_Setting_Tab");
        } else {
            // Original logic for other tabs
            tabBtn = document.getElementById(
                (["OIDC_Settings", "Identity_Provider", "Choose_Application_Type", "Group_Mapping", "Attribute_Mapping"].includes(activeTab) ?
                    "Apps_Tab" : activeTab + "_Tab")
            );
        }

        if (tabBtn) tabBtn.classList.add("active");
    }

    const supportIcon = document.getElementById("openSupportForm");
    const helpContainer = document.getElementById("help");
    const floatingSupportForm = document.getElementById("supportForm");
    if (supportIcon) {
        if (activeTab === "Account") {
            supportIcon.style.display = "none";
            if (helpContainer) {
                helpContainer.style.display = "none";
            }
            if (floatingSupportForm) {
                floatingSupportForm.style.display = "none";
            }
        } else {
            supportIcon.style.display = "flex";
        }
    }
}

function testConfiguration(url) {
    if (!url) {
        console.error('No URL provided for test configuration');
        return;
    }
    
    const appName = document.getElementById("app_name")?.value;
    if (!appName) {
        console.error('No app name found');
        return;
    }

    const cleanedUrl = url.replace(/\s+/g, '');
    const testUrl = `${cleanedUrl}/?RelayState=testconfig&app=${appName}`;
    
    
    const myWindow = window.open(
        testUrl,
        "Test Attribute Configuration",
        "width=600,height=600"
    );
    
    if (myWindow) {
        myWindow.focus();
    } else {
        console.error('Failed to open test configuration window');
    }
}

// Add event listeners for test configuration buttons
document.addEventListener('DOMContentLoaded', function() {
    const testConfigButtons = document.querySelectorAll('[id="testConfigBtn"]');
    testConfigButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('onClick').match(/'([^']+)'/)[1];
            testConfiguration(url);
        });
    });
});

function cancel() {
    const samlForm = document.getElementById("saml_form");
    const uploadForm = document.getElementById("upload_metadata_form");
    const supportForm = document.getElementById("support_form");
    const spSettings = document.getElementById("sp_settings");
    
    if (samlForm) {
        samlForm.style.display = "block";
    }
    
    if (uploadForm) {
        uploadForm.style.display = "none";
    }
    
    if (supportForm) {
        supportForm.style.opacity = "1";
    }
    
    if (spSettings) {
        spSettings.style.opacity = "1";
    }
}

function initializeProxy() {
    const proxyCheckbox = document.getElementById('proxy');
    const proxyDiv = document.getElementById('proxyEnabledDiv');
    
    if (proxyCheckbox && proxyDiv) {
        // Set initial state
        proxyDiv.style.display = proxyCheckbox.checked ? "block" : "none";
        
        // Add change event listener
        proxyCheckbox.addEventListener('change', function() {
            proxyDiv.style.display = this.checked ? "block" : "none";
        });
    }
}

function addCustomAttribute() {
    const val = $("#customAttr").val();
    if (val.length > 0) {
        if ($("#custom_attrs_form").has($("#" + val)).length) {
        } else {
            const div = generateAttributeDiv(val);
            $("#submit_custom_attr").before(div);
            $("#customAttr").val("");
        }
    } else {
    }
}

function generateAttributeDiv(name) {
    const attributeDiv = $("<div>", { 'class': 'gm-div', 'id': name + 'Div' });
    const labelForAttr = $("<label>", { 'for': name, 'class': 'form-control gm-input-label' }).text(name);
    const inputAttr = $("<input>", {
        'id': name,
        'name': name,
        'class': 'form-control gm-input',
        'type': 'text',
        'placeholder': 'Enter name of IDP attribute'
    });
    attributeDiv.append(labelForAttr, inputAttr);
    return attributeDiv;
}

function deleteCustomAttribute() {
    const val = $("#customAttr").val();
    if (val.length > 1) {
        $('#' + val + 'Div').remove();
        $("#customAttr").val("");
    }
}

document.addEventListener("DOMContentLoaded", () => {
    // Attach event listener to all remove buttons
    document.querySelectorAll(".remove-key").forEach(button => {
        button.addEventListener("click", (event) => {
            event.preventDefault();
            const parentDiv = event.target.closest(".flex"); // Find the parent div
            const keyInput = parentDiv.querySelector("input[type='text']:not([disabled])"); // Find the first input (key)

            if (keyInput) {
                keyInput.value = ""; // Clear the value of the key input
            }
        });
    });
});


