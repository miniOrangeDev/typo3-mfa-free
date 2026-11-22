document.addEventListener("DOMContentLoaded", function () {

    // Use a more robust initialization approach
    (function() {
        function initSupportForm() {
            
            const openSupportForm = document.getElementById("openSupportForm");
            const supportForm = document.getElementById("supportForm");
            const closeSupportForm = document.getElementById("closeSupportForm");
            const contactForm = document.getElementById("contactForm");
            const successMessage = document.getElementById("successMessage");
            const contactForm_inside = document.getElementById("contactForm_inside");
            const helpContainer = document.getElementById("help");

            // Modal support form elements
            const modalSupportForm = document.getElementById("modal_support_form");
            const modalSubmitBtn = document.getElementById("modal_submit_btn");
            const modalResponse = document.getElementById("modal_support_response");
            let successCloseTimer = null;

            function clearSuccessCloseTimer() {
                if (successCloseTimer) {
                    clearTimeout(successCloseTimer);
                    successCloseTimer = null;
                }
            }

            function scheduleSuccessClose() {
                clearSuccessCloseTimer();
                successCloseTimer = setTimeout(function() {
                    successCloseTimer = null;
                    if (supportForm) {
                        supportForm.style.display = "none";
                    }
                    resetSupportPopup();
                }, 12000);
            }

           
            // Submit the popup support form without leaving the current tab
            if (modalSupportForm) {
                modalSupportForm.addEventListener("submit", function(e) {
                    e.preventDefault();

                    const emailInput = document.getElementById("modal_email");
                    const queryInput = document.getElementById("modal_query");
                    const email = emailInput ? emailInput.value.trim() : '';
                    const query = queryInput ? queryInput.value.trim() : '';

                    if (!email || !query) {
                        showModalResponse('Please fill in all required fields.', 'error');
                        return;
                    }

                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(email)) {
                        showModalResponse('Please enter a valid email address.', 'error');
                        return;
                    }

                    const currentTabField = document.getElementById("support_current_tab");
                    const activeTab = document.querySelector(".tablinks.active");
                    if (currentTabField && activeTab && activeTab.id) {
                        currentTabField.value = activeTab.id.replace(/_Tab$/, '');
                    }

                    showLoadingState();

                    const formData = new FormData(modalSupportForm);
                    fetch("", {
                        method: "POST",
                        headers: {
                            "X-Requested-With": "XMLHttpRequest",
                            "Accept": "application/json"
                        },
                        body: formData,
                        credentials: "same-origin"
                    })
                        .then(function(response) {
                            const contentType = response.headers.get("content-type") || "";
                            if (!contentType.includes("application/json")) {
                                throw new Error("Unexpected response");
                            }
                            return response.json();
                        })
                        .then(function(data) {
                            const isSuccess = data && data.status === "success";
                            if (isSuccess) {
                                showSupportSuccess();
                                modalSupportForm.reset();
                                if (currentTabField && activeTab && activeTab.id) {
                                    currentTabField.value = activeTab.id.replace(/_Tab$/, '');
                                }
                            } else {
                                showModalResponse((data && data.message) ? data.message : 'Something went wrong. Please try again.', 'error');
                            }
                        })
                        .catch(function() {
                            showModalResponse("Something went wrong. Please try again.", "error");
                        })
                        .finally(function() {
                            resetLoadingState();
                        });
                });
            }

            function showSupportSuccess() {
                if (supportForm) {
                    supportForm.classList.add("is-success");
                }
                if (modalResponse) {
                    modalResponse.style.display = "none";
                    modalResponse.textContent = "";
                }
                scheduleSuccessClose();
            }

            function resetSupportPopup() {
                clearSuccessCloseTimer();
                if (supportForm) {
                    supportForm.classList.remove("is-success");
                }
                if (successMessage) {
                    successMessage.style.display = "none";
                }
                if (contactForm) {
                    contactForm.reset();
                }
                if (modalSupportForm) {
                    modalSupportForm.reset();
                }
                if (modalResponse) {
                    modalResponse.style.display = "none";
                    modalResponse.textContent = "";
                    modalResponse.className = "alert support-form-response";
                }
                resetLoadingState();
            }

            // Function to show modal response messages
            function showModalResponse(message, type) {
                if (!modalResponse) return;
                if (type === 'success') {
                    showSupportSuccess();
                    return;
                }

                modalResponse.textContent = message;
                modalResponse.className = 'alert support-form-response alert-' + (type === 'error' ? 'danger' : 'info');
                modalResponse.style.display = 'block';
            }

            // Function to show loading state
            function showLoadingState() {
                const submitBtn = document.getElementById("modal_submit_btn");
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Submitting...';
                    submitBtn.style.backgroundColor = '#6c757d';
                    submitBtn.style.cursor = 'not-allowed';
                }
            }

            function resetLoadingState() {
                const submitBtn = document.getElementById("modal_submit_btn");
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit';
                    submitBtn.style.backgroundColor = '';
                    submitBtn.style.cursor = '';
                }
            }

            // Open support form
            if (openSupportForm) {
                openSupportForm.addEventListener("click", function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    supportForm.style.display = "block";
                    resetSupportPopup();
                });
            }

            // Close support form
            if (closeSupportForm) {
                closeSupportForm.addEventListener("click", function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    supportForm.style.display = "none";
                    resetSupportPopup();
                });
            }

            // Form submission (for the old contact form)
            if (contactForm) {
                contactForm.addEventListener("submit", function(e) {
                    e.preventDefault();

                    const email = document.getElementById("email").value.trim();
                    const query = document.getElementById("query").value.trim();

                    if (!email || !query) {
                        alert("Please fill in all required fields.");
                        return;
                    }

                    // Email validation
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(email)) {
                        alert("Please enter a valid email address.");
                        return;
                    }

                    // Submit the form normally to the controller
                    contactForm.submit();
                });
            }

            // Handle support message display and form reset
            const supportMessage = document.getElementById("supportMessage");
            
            // Check if support form was submitted and response is available
            if (supportMessage && supportMessage.style.display === "block") {
                // Check if it's a success message
                const isSuccess = supportMessage.querySelector('.right_image').textContent.includes('✅');
                
                if (isSuccess) {
                    // Show success message for 10 seconds, then reset form
                    setTimeout(() => {
                        supportMessage.style.display = "none";
                        contactForm_inside.style.display = "block";
                        contactForm.reset();
                    }, 10000); // 10 seconds
                } else {
                    // For error messages, show for 5 seconds then reset
                    setTimeout(() => {
                        supportMessage.style.display = "none";
                        contactForm_inside.style.display = "block";
                        // Don't reset form on error so user can fix and resubmit
                    }, 5000); // 5 seconds
                }
            }

            // Help container functionality
            if (openSupportForm && helpContainer) {
                // First visit check
                if (!localStorage.getItem("hasVisited")) {
                    helpContainer.style.display = "block";
                    localStorage.setItem("hasVisited", "true");
                    setTimeout(() => {
                        helpContainer.style.display = "none";
                    }, 10000);
                } else {
                    helpContainer.style.display = "none";
                }

                // Hover events
                openSupportForm.addEventListener("mouseenter", function() {
                    helpContainer.style.display = "block";
                });

                openSupportForm.addEventListener("mouseleave", function() {
                    helpContainer.style.display = "none";
                });

                helpContainer.addEventListener("mouseenter", function() {
                    helpContainer.style.display = "block";
                });

                helpContainer.addEventListener("mouseleave", function() {
                    helpContainer.style.display = "none";
                });
            }

            // Close on outside click
            window.addEventListener("click", function(e) {
                if (e.target === supportForm) {
                    supportForm.style.display = "none";
                    resetSupportPopup();
                }
            });

            // Close on Escape key
            document.addEventListener("keydown", function(e) {
                if (e.key === "Escape" && supportForm && supportForm.style.display === "block") {
                    supportForm.style.display = "none";
                    resetSupportPopup();
                }
            });

            if (supportForm && supportForm.classList.contains("is-success") && supportForm.style.display === "block") {
                scheduleSuccessClose();
            }

        }

        // Initialize when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSupportForm);
        } else {
            initSupportForm();
        }
    })();
});
