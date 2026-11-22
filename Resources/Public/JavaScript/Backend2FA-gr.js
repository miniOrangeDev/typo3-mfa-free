/**
 * Backend 2FA – gr.html (digit inputs, go back, resend, contact admin).
 */
function getUrlParameter(name) {
    name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
}

document.addEventListener('DOMContentLoaded', function() {
    var email = getUrlParameter('email');
    if (email && document.getElementById('emailField')) {
        document.getElementById('emailField').value = email;
    }
});

var form = document.querySelector('form');
if (form) {
    form.addEventListener('submit', function(e) {
        var combinedInput = document.getElementById('combinedCode');
        if (combinedInput) {
            var digits = Array.from(document.querySelectorAll('.input-group input[type="text"]'))
                .map(function(input) { return input.value; })
                .join('');
            combinedInput.value = digits;
        }
    });
}

document.querySelectorAll('.input-group input').forEach(function(input, index, inputs) {
    input.addEventListener('input', function() {
        if (this.value.length === 1 && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
    });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
            inputs[index - 1].focus();
        }
    });
});

function goBack() {
    window.history.back();
}

function resendCode() {
    alert('Resend code functionality to be implemented');
}

function contactAdmin() {
    alert('Contact admin functionality to be implemented');
}
