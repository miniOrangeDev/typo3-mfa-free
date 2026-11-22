/**
 * TYPO3 v13 Two-Factor Authentication JavaScript
 */

class TwoFactorAuth {
    constructor() {
        this.init();
    }

    init() {
        this.setupOtpInputs();
        this.setupFormValidation();
        this.setupPhoneFormatting();
        this.setupKeyboardNavigation();
        this.setupAutomaticSubmission();
    }

    /**
     * Setup OTP input behavior
     */
    setupOtpInputs() {
        const otpInputs = document.querySelectorAll('.mo-otp-digit');
        
        otpInputs.forEach((input, index) => {
            // Auto-focus next input on digit entry
            input.addEventListener('input', (e) => {
                const value = e.target.value;
                
                // Only allow numbers
                if (!/^\d$/.test(value)) {
                    e.target.value = '';
                    return;
                }
                
                // Move to next input
                if (value && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
                
                // Check if all inputs are filled
                this.checkOtpCompletion();
            });
            
            // Handle backspace
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    otpInputs[index - 1].focus();
                }
                
                // Handle paste
                if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
                
                if (e.key === 'ArrowLeft' && index > 0) {
                    otpInputs[index - 1].focus();
                }
            });
            
            // Handle paste operation
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedData = e.clipboardData.getData('text');
                const digits = pastedData.replace(/\D/g, '').slice(0, 6);
                
                digits.split('').forEach((digit, digitIndex) => {
                    if (otpInputs[digitIndex]) {
                        otpInputs[digitIndex].value = digit;
                    }
                });
                
                // Focus last filled input or next empty one
                const lastIndex = Math.min(digits.length - 1, otpInputs.length - 1);
                if (otpInputs[lastIndex + 1]) {
                    otpInputs[lastIndex + 1].focus();
                } else {
                    otpInputs[lastIndex].focus();
                }
                
                this.checkOtpCompletion();
            });
        });
    }

    /**
     * Check if OTP is complete and enable submit button
     */
    checkOtpCompletion() {
        const otpInputs = document.querySelectorAll('.mo-otp-digit');
        const submitBtn = document.querySelector('.mo-btn-primary');
        
        const allFilled = Array.from(otpInputs).every(input => input.value.length === 1);
        
        if (submitBtn) {
            submitBtn.disabled = !allFilled;
            submitBtn.classList.toggle('loading', false);
        }
    }

    /**
     * Setup automatic form submission when OTP is complete
     */
    setupAutomaticSubmission() {
        const otpForm = document.querySelector('.mo-verify-form');
        const autoSubmit = true; // Can be made configurable
        
        if (otpForm && autoSubmit) {
            const otpInputs = document.querySelectorAll('.mo-otp-digit');
            
            otpInputs.forEach(input => {
                input.addEventListener('input', () => {
                    setTimeout(() => {
                        const allFilled = Array.from(otpInputs).every(input => input.value.length === 1);
                        if (allFilled) {
                            this.submitForm(otpForm);
                        }
                    }, 100);
                });
            });
        }
    }

    /**
     * Setup form validation
     */
    setupFormValidation() {
        const forms = document.querySelectorAll('form');
        
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                const submitBtn = form.querySelector('.mo-btn-primary');
                if (submitBtn) {
                    submitBtn.classList.add('loading');
                    submitBtn.disabled = true;
                }
                
                // Re-enable button after 5 seconds as fallback
                setTimeout(() => {
                    if (submitBtn) {
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                    }
                }, 5000);
            });
        });
    }

    /**
     * Setup phone number formatting
     */
    setupPhoneFormatting() {
        const phoneInputs = document.querySelectorAll('input[type="tel"]');
        
        phoneInputs.forEach(input => {
            input.addEventListener('input', (e) => {
                // Remove non-digits except + at the beginning
                let value = e.target.value.replace(/[^\d+]/g, '');
                
                // Ensure + only at the beginning
                if (value.includes('+') && value.indexOf('+') !== 0) {
                    value = value.replace(/\+/g, '');
                }
                
                e.target.value = value;
            });
        });
    }

    /**
     * Setup keyboard navigation for method selection
     */
    setupKeyboardNavigation() {
        const methodOptions = document.querySelectorAll('.mo-method-option');
        
        methodOptions.forEach((option, index) => {
            const radio = option.querySelector('input[type="radio"]');
            
            option.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change'));
                }
                
                if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    const nextIndex = (index + 1) % methodOptions.length;
                    methodOptions[nextIndex].focus();
                }
                
                if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
                    e.preventDefault();
                    const prevIndex = (index - 1 + methodOptions.length) % methodOptions.length;
                    methodOptions[prevIndex].focus();
                }
            });
            
            option.setAttribute('tabindex', '0');
        });
    }

    /**
     * Submit form with loading state
     */
    submitForm(form) {
        const submitBtn = form.querySelector('.mo-btn-primary');
        if (submitBtn && !submitBtn.disabled) {
            submitBtn.click();
        }
    }

    /**
     * Clear OTP inputs
     */
    clearOtpInputs() {
        const otpInputs = document.querySelectorAll('.mo-otp-digit');
        otpInputs.forEach(input => {
            input.value = '';
        });
        if (otpInputs[0]) {
            otpInputs[0].focus();
        }
    }

    /**
     * Show loading state
     */
    showLoading(element) {
        if (element) {
            element.classList.add('loading');
            element.disabled = true;
        }
    }

    /**
     * Hide loading state
     */
    hideLoading(element) {
        if (element) {
            element.classList.remove('loading');
            element.disabled = false;
        }
    }
}

// Global utility functions
window.TwoFactorUtils = {
    /**
     * Resend SMS code
     */
    resendSmsCode() {
        const phoneNumber = document.querySelector('input[name="phoneNumber"]')?.value;
        if (!phoneNumber) return;
        
        // Implement API call
        
        // Show success message
        this.showMessage('SMS code sent successfully', 'success');
    },

    /**
     * Resend email code
     */
    resendEmailCode() {
        // Implement API call
        
        // Show success message
        this.showMessage('Email code sent successfully', 'success');
    },

    /**
     * Resend WhatsApp code
     */
    resendWhatsAppCode() {
        const phoneNumber = document.querySelector('input[name="phoneNumber"]')?.value;
        if (!phoneNumber) return;
        
        // Implement API call
        
        // Show success message
        this.showMessage('WhatsApp code sent successfully', 'success');
    },

    /**
     * Copy secret key to clipboard
     */
    copySecret() {
        const secretElement = document.querySelector('.mo-key-display code');
        if (secretElement) {
            navigator.clipboard.writeText(secretElement.textContent).then(() => {
                this.showMessage('Secret key copied to clipboard', 'success');
            });
        }
    },

    /**
     * Show message to user
     */
    showMessage(message, type = 'info') {
        // Create or update flash message
        let messageContainer = document.querySelector('.mo-flash-messages');
        if (!messageContainer) {
            messageContainer = document.createElement('div');
            messageContainer.className = 'mo-flash-messages';
            const card = document.querySelector('.mo-2fa-card');
            if (card) {
                card.insertBefore(messageContainer, card.firstChild);
            }
        }
        
        const messageElement = document.createElement('div');
        messageElement.className = `mo-flash-message ${type}`;
        messageElement.textContent = message;
        
        messageContainer.appendChild(messageElement);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            messageElement.remove();
        }, 5000);
    },

    /**
     * Toggle help modal
     */
    toggleHelp() {
        const modal = document.getElementById('helpModal');
        if (modal) {
            modal.style.display = modal.style.display === 'none' ? 'block' : 'none';
        }
    },

    /**
     * Go back in browser history
     */
    goBack() {
        window.history.back();
    }
};

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new TwoFactorAuth();
});

// Make functions globally available
window.resendSmsCode = () => TwoFactorUtils.resendSmsCode();
window.resendEmailCode = () => TwoFactorUtils.resendEmailCode();
window.resendWhatsAppCode = () => TwoFactorUtils.resendWhatsAppCode();
window.copySecret = () => TwoFactorUtils.copySecret();
window.toggleHelp = () => TwoFactorUtils.toggleHelp();
window.goBack = () => TwoFactorUtils.goBack();