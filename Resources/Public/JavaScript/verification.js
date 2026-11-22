document.addEventListener('DOMContentLoaded', function() {
    // Auto-focus and auto-tab between OTP inputs
    const otpInputs = document.querySelectorAll('.input-group input');
    if (otpInputs.length > 0) {
        otpInputs.forEach((input, index) => {
            // Focus on first input
            if (index === 0) {
                input.focus();
            }
            
            // Handle input
            input.addEventListener('input', function() {
                if (this.value.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });
            
            // Handle backspace
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
                    otpInputs[index - 1].focus();
                }
            });
        });
    }
    
    // Get email from URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const email = urlParams.get('email');
    if (email) {
        const emailFields = document.querySelectorAll('input[name="email"]');
        emailFields.forEach(field => {
            field.value = email;
        });
    }
});

function goBack() {
    window.history.back();
}

function resendCode(method) {
    // Implement AJAX call to resend code
    // You would typically make a fetch request to your backend here
    alert(`A new ${method} code has been sent`);
}

function contactAdmin() {
    // Implement contact admin functionality
    alert('Please contact your system administrator for assistance');
}