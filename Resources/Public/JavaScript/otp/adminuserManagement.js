        (function() {
            // Using an IIFE (Immediately Invoked Function Expression) 
            // to prevent variable name conflicts with other extensions
            document.addEventListener('DOMContentLoaded', function() {
                const selectAllCheckbox = document.getElementById('selectAll');
                const userCheckboxes = document.querySelectorAll('.user-checkbox');
                
                if (selectAllCheckbox) {
                    selectAllCheckbox.addEventListener('change', function() {
                        userCheckboxes.forEach(checkbox => {
                            checkbox.checked = this.checked;
                        });
                    });
                }
                
                userCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const allChecked = Array.from(userCheckboxes).every(cb => cb.checked);
                        const someChecked = Array.from(userCheckboxes).some(cb => cb.checked);
                        if (selectAllCheckbox) {
                            selectAllCheckbox.checked = allChecked;
                            selectAllCheckbox.indeterminate = someChecked && !allChecked;
                        }
                    });
                });
                
                const bulkActionSelect = document.getElementById('bulkAction');
                if (bulkActionSelect) {
                    bulkActionSelect.addEventListener('change', function() {
                        const selectedAction = this.value;
                        if (!selectedAction) return;
                        
                        const selectedUsers = Array.from(userCheckboxes)
                            .filter(cb => cb.checked)
                            .map(cb => cb.value);
                        
                        if (selectedUsers.length === 0) {
                            alert('Please select at least one user to perform the action.');
                            this.value = '';
                            return;
                        }
                        
                        let confirmMessage = '';
                        let optionValue = '';
                        
                        if (selectedAction === 'reset') {
                            confirmMessage = 'Are you sure you want to reset 2FA for ' + selectedUsers.length + ' user(s)?';
                            optionValue = 'bulk_reset_2fa';
                        } else if (selectedAction === 'disable') {
                            confirmMessage = 'Are you sure you want to disable 2FA for ' + selectedUsers.length + ' user(s)?';
                            optionValue = 'bulk_disable_2fa';
                        }
                        
                        if (confirm(confirmMessage)) {
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = '';
                            
                            const optionInput = document.createElement('input');
                            optionInput.type = 'hidden';
                            optionInput.name = 'option';
                            optionInput.value = optionValue;
                            form.appendChild(optionInput);
                            
                            selectedUsers.forEach(userId => {
                                const userInput = document.createElement('input');
                                userInput.type = 'hidden';
                                userInput.name = 'user_ids[]';
                                userInput.value = userId;
                                form.appendChild(userInput);
                            });
                            
                            document.body.appendChild(form);
                            form.submit();
                        } else {
                            this.value = '';
                        }
                    });
                }
            });
        })();
