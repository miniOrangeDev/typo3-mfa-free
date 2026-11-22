/**
 * Backend 2FA – Show.html (method selection, email from URL).
 */
function getUrlParameter(name) {
    name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
}

document.addEventListener('DOMContentLoaded', function() {
    var email = getUrlParameter('email');
    var emailInput = document.getElementById('email');
    if (email && emailInput) {
        emailInput.value = email;
    }
});

function selectMethod(methodId) {
    document.querySelectorAll('.twofa-method').forEach(function(method) {
        method.classList.remove('selected');
    });
    var radioButton = document.getElementById(methodId);
    if (radioButton) {
        var selectedMethod = radioButton.closest('.twofa-method');
        if (selectedMethod) {
            selectedMethod.classList.add('selected');
        }
        radioButton.checked = true;
    }
}

var emailForm = document.getElementById('emailForm');
if (emailForm) {
    emailForm.addEventListener('submit', function(e) {
        var email = getUrlParameter('email');
        var emailInput = document.getElementById('email');
        if (emailInput && email) {
            emailInput.value = email;
        }
    });
}
