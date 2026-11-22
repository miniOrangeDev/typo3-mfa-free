/**
 * Backend 2FA – OTP form behaviour (auto-focus, paste, submit merge).
 * Use on any template that has #otpForm and .otp-input fields.
 */
(function() {
    var form = document.getElementById('otpForm');
    if (!form) return;

    var inputs = [].slice.call(form.querySelectorAll('input[type="text"]'));
    var otpLengthEl = document.getElementById('otp_length');

    function getOtpLength() {
        return otpLengthEl ? parseInt(otpLengthEl.value, 10) : 6;
    }

    inputs.forEach(function(input, idx) {
        input.addEventListener('input', function() {
            if (this.value.length === 1 && idx < inputs.length - 1) {
                inputs[idx + 1].focus();
            }
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && this.value === '' && idx > 0) {
                inputs[idx - 1].focus();
            }
        });
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            var pastedData = (e.clipboardData || window.clipboardData).getData('text');
            var otpDigits = pastedData.replace(/\D/g, '').split('');
            var otpLength = getOtpLength();
            for (var i = 0; i < Math.min(otpDigits.length, otpLength); i++) {
                if (inputs[i]) inputs[i].value = otpDigits[i];
            }
            var lastFilledIndex = Math.min(otpDigits.length - 1, otpLength - 1);
            if (inputs[lastFilledIndex]) inputs[lastFilledIndex].focus();
        });
    });

    form.addEventListener('submit', function(e) {
        var otp = '';
        var len = getOtpLength();
        for (var i = 1; i <= len; i++) {
            var el = document.getElementById('otp' + i);
            otp += (el ? el.value : '') || '';
        }
        var hiddenOtp = document.getElementById('otp');
        if (hiddenOtp) hiddenOtp.value = otp;
    });
})();

/**
 * Resend code countdown (when #resendOtpBtn and #resendOtpText exist, e.g. EmailSmsVerify).
 */
(function() {
    var btn = document.getElementById('resendOtpBtn');
    var textEl = document.getElementById('resendOtpText');
    if (!btn || !textEl) return;

    var RESEND_COOLDOWN_SEC = 60;
    var remaining = RESEND_COOLDOWN_SEC;

    function updateResendBtn() {
        if (remaining <= 0) {
            btn.disabled = false;
            btn.style.color = '#ff9100';
            btn.style.cursor = 'pointer';
            textEl.textContent = 'Resend Code';
            if (window.resendTimer) clearInterval(window.resendTimer);
            return;
        }
        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        textEl.textContent = 'Resend Code (' + m + ':' + (s < 10 ? '0' : '') + s + ')';
        remaining--;
    }

    updateResendBtn();
    window.resendTimer = setInterval(updateResendBtn, 1000);
})();

function contactAdmin() {
    alert('Please contact your administrator for assistance.');
}
