(function() {
    'use strict';

    var currentPhase = 'setup';

    function showVerificationPhase() {
        var setupPhase = document.getElementById('setupPhase');
        var verificationPhase = document.getElementById('verificationPhase');
        if (setupPhase) setupPhase.classList.remove('active');
        if (verificationPhase) verificationPhase.classList.add('active');
        currentPhase = 'verification';
    }

    function showSetupPhase() {
        var setupPhase = document.getElementById('setupPhase');
        var verificationPhase = document.getElementById('verificationPhase');
        if (verificationPhase) verificationPhase.classList.remove('active');
        if (setupPhase) setupPhase.classList.add('active');
        currentPhase = 'setup';
    }

    function copyKey() {
        var secretEl = document.getElementById('manualKey');
        if (!secretEl) return;
        var secret = secretEl.textContent;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(secret).then(function() {
                var copiedMessage = document.getElementById('copiedMessage');
                if (copiedMessage) {
                    copiedMessage.style.display = 'block';
                    setTimeout(function() {
                        copiedMessage.style.display = 'none';
                    }, 2000);
                }
            });
        }
    }

    function contactAdmin() {
        alert('Please contact your administrator for assistance.');
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Input handling
        var inputs = document.querySelectorAll('.otp-input');
        
        inputs.forEach(function(input, idx) {
            input.addEventListener('input', function(e) {
                if (this.value.length === 1 && idx < inputs.length - 1) {
                    inputs[idx + 1].focus();
                }
            });
            
            input.addEventListener('keydown', function(e) {
                if (e.key === "Backspace" && this.value === "" && idx > 0) {
                    inputs[idx - 1].focus();
                }
            });
            
            input.addEventListener('paste', function(e) {
                e.preventDefault();
                var clipboardData = e.clipboardData || window.clipboardData;
                if (!clipboardData) return;
                
                var pastedData = clipboardData.getData('text');
                var otpDigits = pastedData.replace(/\D/g, '').split('');
                var otpLength = 6;
                
                for (var i = 0; i < Math.min(otpDigits.length, otpLength); i++) {
                    if (inputs[i]) {
                        inputs[i].value = otpDigits[i];
                    }
                }
                
                var lastFilledIndex = Math.min(otpDigits.length - 1, otpLength - 1);
                if (inputs[lastFilledIndex]) {
                    inputs[lastFilledIndex].focus();
                }
            });
        });

        // Form submission
        var otpForm = document.getElementById('otpForm');
        if (otpForm) {
            otpForm.addEventListener('submit', function(e) {
                var otp = '';
                for (var i = 1; i <= 6; i++) {
                    var el = document.getElementById('otp' + i);
                    if (el) otp += (el.value || '');
                }
                var otpHidden = document.getElementById('otp');
                if (otpHidden) otpHidden.value = otp;
            });
        }

        // Initial phase logic
        var isConfiguredEl = document.getElementById('isConfigured');
        var isConfigured = isConfiguredEl && isConfiguredEl.value == '1';
        var errorMessage = document.querySelector('.error-message');

        if (isConfigured || errorMessage) {
            showVerificationPhase();
        } else {
            showSetupPhase();
        }

        // Event Listeners
        var helpBtn = document.getElementById('twofa-help-btn');
        if (helpBtn) {
            helpBtn.addEventListener('click', function(e) {
                e.preventDefault();
                contactAdmin();
            });
        }

        var setupNextBtn = document.getElementById('setup-next-btn');
        if (setupNextBtn) {
            setupNextBtn.addEventListener('click', function(e) {
                e.preventDefault();
                showVerificationPhase();
            });
        }

        var copyKeyContainer = document.getElementById('copy-key-container');
        if (copyKeyContainer) {
            copyKeyContainer.addEventListener('click', function() {
                copyKey();
            });
        }

        var backBtn = document.querySelector('.twofa-back');
        if (backBtn) {
            backBtn.addEventListener('click', function(e) {
                if (currentPhase === 'verification' && !isConfigured) {
                    // New user: verify → setup (QR phase)
                    e.preventDefault();
                    showSetupPhase();
                }
                
            });
        }
    });
})();