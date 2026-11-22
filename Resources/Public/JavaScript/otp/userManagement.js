// User Management Search Functions
document.addEventListener("DOMContentLoaded", function() {
    initializeUserManagementSearch();
});

// Global variables for pagination
let currentPage = 1;
let entriesPerPage = 10;
let allTableRows = [];
let filteredRows = [];

function initializeUserManagementSearch() {
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearch');
    const entriesSelect = document.getElementById('entries');
    const selectAllCheckbox = document.getElementById('selectAll');
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    const bulkActionSelect = document.getElementById('bulkAction');
    
    // Initialize all table rows
    allTableRows = Array.from(document.querySelectorAll('table tbody tr')).filter(row => row.id !== 'noResultsRow');
    filteredRows = [...allTableRows];
    
    // Set initial entries per page based on dropdown selection
    if (entriesSelect) {
        const selectedValue = entriesSelect.value;
        if (selectedValue === 'all') {
            entriesPerPage = filteredRows.length;
        } else {
            entriesPerPage = parseInt(selectedValue);
        }
    }
    
    // Initialize pagination
    updatePagination();
    
    if (searchInput) {
        // Add event listener for search input
        searchInput.addEventListener('input', function() {
            performSearch();
        });
        
        // Initialize search if there's an initial value
        if (searchInput.value) {
            performSearch();
        }
    }
    
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            clearSearch();
        });
        
        // Show/hide clear button based on initial search value
        if (searchInput && searchInput.value) {
            clearSearchBtn.style.display = 'block';
        } else {
            clearSearchBtn.style.display = 'none';
        }
    }
    
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            const limit = this.value;
            
            if (limit === 'all') {
                entriesPerPage = filteredRows.length;
            } else {
                entriesPerPage = parseInt(limit);
            }
            
            currentPage = 1; // Reset to first page
            updatePagination();
        });
    }
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            // Only select/deselect currently visible checkboxes (based on pagination)
            const visibleCheckboxes = Array.from(userCheckboxes).filter(checkbox => {
                const row = checkbox.closest('tr');
                return row && row.style.display !== 'none';
            });
            
            visibleCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
        });
    }
    
    // Handle individual checkboxes
    userCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (!this.checked) {
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                }
            } else {
                // Check if all visible checkboxes are checked
                const visibleCheckboxes = Array.from(userCheckboxes).filter(cb => {
                    const row = cb.closest('tr');
                    return row && row.style.display !== 'none';
                });
                const allVisibleChecked = visibleCheckboxes.every(cb => cb.checked);
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = allVisibleChecked;
                }
            }
        });
    });
    
    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', function() {
            const action = this.value;
            
            if (!action) {
                return; // No action selected, just return
            }
            
            // Only get selected users from currently visible checkboxes
            const visibleCheckboxes = Array.from(userCheckboxes).filter(checkbox => {
                const row = checkbox.closest('tr');
                return row && row.style.display !== 'none';
            });
            
            const selectedUsers = visibleCheckboxes
                .filter(cb => cb.checked)
                .map(cb => cb.value);
            
            if (selectedUsers.length === 0) {
                alert('Please select at least one user');
                this.value = '';
                return;
            }
            
            let confirmMessage = '';
            if (action === 'reset') {
                confirmMessage = 'Are you sure you want to reset 2FA for the selected users? This will remove them from the 2FA system.';
            } else if (action === 'disable') {
                confirmMessage = 'Are you sure you want to disable 2FA for the selected users?';
            } else {
                alert('Please select a valid action');
                this.value = '';
                return;
            }
            
            if (confirm(confirmMessage)) {
                // Create a hidden form for bulk action
                const bulkForm = document.createElement('form');
                bulkForm.method = 'POST';
                bulkForm.action = window.location.href;
                bulkForm.style.display = 'none';
                
                // Add action type
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'option';
                actionInput.value = action === 'reset' ? 'bulk_reset_2fa' : 'bulk_disable_2fa';
                bulkForm.appendChild(actionInput);
                
                // Add selected user IDs
                selectedUsers.forEach(userId => {
                    const userInput = document.createElement('input');
                    userInput.type = 'hidden';
                    userInput.name = 'user_ids[]';
                    userInput.value = userId;
                    bulkForm.appendChild(userInput);
                });
                
                // Add to document and submit
                document.body.appendChild(bulkForm);
                bulkForm.submit();
            }
            
            // Reset select
            this.value = '';
        });
    }
    

}

function performSearch() {
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearch');
    const searchTerm = searchInput.value.toLowerCase().trim();
    
    if (searchTerm === '') {
        clearSearch();
        return;
    }
    
    // Show clear button
    if (clearSearchBtn) {
        clearSearchBtn.style.display = 'block';
    }
    
    // Filter rows based on search term 
    filteredRows = allTableRows.filter(row => {
        const username = row.querySelector('td:nth-child(2)')?.textContent?.toLowerCase() || '';
        const email = row.querySelector('td:nth-child(3)')?.textContent?.toLowerCase() || '';
        const website = row.querySelector('td:nth-child(4)')?.textContent?.toLowerCase() || '';
        const role = row.querySelector('td:nth-child(5)')?.textContent?.toLowerCase() || '';
        const method = row.querySelector('td:nth-child(6)')?.textContent?.toLowerCase() || '';
        const status = row.querySelector('td:nth-child(7)')?.textContent?.toLowerCase() || '';
        
        return username.includes(searchTerm) ||
               email.includes(searchTerm) || 
               website.includes(searchTerm) || 
               role.includes(searchTerm) || 
               method.includes(searchTerm) || 
               status.includes(searchTerm);
    });
    
    // Reset to first page
    currentPage = 1;
    
    // Update pagination
    updatePagination();
}

function clearSearch() {
    const searchInput = document.getElementById('searchInput');
    const clearSearchBtn = document.getElementById('clearSearch');
    const noResultsRow = document.getElementById('noResultsRow');
    
    if (searchInput) {
        searchInput.value = '';
    }
    
    if (clearSearchBtn) {
        clearSearchBtn.style.display = 'none';
    }
    
    // Reset filtered rows to all rows
    filteredRows = [...allTableRows];
    currentPage = 1;
    
    // Remove no results row
    if (noResultsRow) {
        noResultsRow.remove();
    }
    
    // Update pagination
    updatePagination();
}

function updatePagination() {
    const tbody = document.querySelector('table tbody');
    const noResultsRow = document.getElementById('noResultsRow');
    const paginationNav = document.getElementById('paginationNav');
    
    // Remove no results row temporarily
    if (noResultsRow) {
        noResultsRow.remove();
    }
    
    // Hide all rows first
    allTableRows.forEach(row => {
        row.style.display = 'none';
    });
    
    // Calculate pagination
    const totalRows = filteredRows.length;
    const totalPages = Math.ceil(totalRows / entriesPerPage);
    
    // Ensure current page is within bounds
    if (currentPage > totalPages) {
        currentPage = totalPages || 1;
    }
    
    // Calculate start and end indices
    const startIndex = (currentPage - 1) * entriesPerPage;
    const endIndex = Math.min(startIndex + entriesPerPage, totalRows);
    
    
    // Show only the rows for current page
    for (let i = startIndex; i < endIndex; i++) {
        if (filteredRows[i]) {
            filteredRows[i].style.display = '';
        }
    }
    
    // Re-add no results row if needed
    if (totalRows === 0 && document.getElementById('searchInput').value !== '') {
        if (!document.getElementById('noResultsRow')) {
            const newNoResultsRow = document.createElement('tr');
            newNoResultsRow.id = 'noResultsRow';
            newNoResultsRow.innerHTML = `
                <td colspan="8" style="text-align: center; padding: 2rem 0;">
                    <i class="fas fa-search fa-3x" style="color: #6c757d; margin-bottom: 1rem;"></i>
                    <p style="color: #6c757d;">No users found matching your search</p>
                </td>
            `;
            tbody.appendChild(newNoResultsRow);
        }
    }
    
    // Update pagination info
    updatePaginationInfo(startIndex + 1, endIndex, totalRows);
    
    // Update pagination buttons
    updatePaginationButtons(totalPages);
    
    // Show/hide pagination based on entries selection
    const entriesSelect = document.getElementById('entries');
    if (entriesSelect && entriesSelect.value === 'all') {
        paginationNav.style.display = 'none';
    } else {
        paginationNav.style.display = 'block';
    }
}

function updatePaginationInfo(startEntry, endEntry, totalEntries) {
    const paginationInfo = document.querySelector('.pagination-info');
    if (!paginationInfo) return;
    
    const searchInput = document.getElementById('searchInput');
    const searchTerm = searchInput ? searchInput.value : '';
    
    let displayText = '';
    if (searchTerm !== '') {
        displayText = `Showing ${startEntry} to ${endEntry} of ${totalEntries} entries (filtered)`;
    } else {
        displayText = `Showing ${startEntry} to ${endEntry} of ${totalEntries} entries`;
    }
    
    paginationInfo.textContent = displayText;
}

function updatePaginationButtons(totalPages) {
    const prevButton = document.getElementById('prevButton');
    const nextButton = document.getElementById('nextButton');
    
    // Update Previous button
    if (prevButton) {
        if (currentPage <= 1) {
            prevButton.classList.add('disabled');
            prevButton.querySelector('a').style.pointerEvents = 'none';
        } else {
            prevButton.classList.remove('disabled');
            prevButton.querySelector('a').style.pointerEvents = 'auto';
        }
    }
    
    // Update Next button
    if (nextButton) {
        if (currentPage >= totalPages) {
            nextButton.classList.add('disabled');
            nextButton.querySelector('a').style.pointerEvents = 'none';
        } else {
            nextButton.classList.remove('disabled');
            nextButton.querySelector('a').style.pointerEvents = 'auto';
        }
    }
}

function goToPreviousPage() {
    if (currentPage > 1) {
        currentPage--;
        updatePagination();
    }
}

function goToNextPage() {
    const totalPages = Math.ceil(filteredRows.length / entriesPerPage);
    if (currentPage < totalPages) {
        currentPage++;
        updatePagination();
    }
}

// Function to confirm and reset 2FA for individual user
function confirmAndReset2FA() {
    const userId = document.getElementById('user_id').value;
    if (confirm('Are you sure you want to reset 2FA for this user? This will remove them from the 2FA system.')) {
        // Create a hidden form for bulk reset action
        const bulkForm = document.createElement('form');
        bulkForm.method = 'POST';
        bulkForm.action = window.location.href;
        bulkForm.style.display = 'none';
        
        // Add action type
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'option';
        actionInput.value = 'bulk_reset_2fa';
        bulkForm.appendChild(actionInput);
        
        // Add user ID as array
        const userInput = document.createElement('input');
        userInput.type = 'hidden';
        userInput.name = 'user_ids[]';
        userInput.value = userId;
        bulkForm.appendChild(userInput);
        
        // Add to document and submit
        document.body.appendChild(bulkForm);
        bulkForm.submit();
    }
}

// Client-side pagination functionality
let paginationCurrentPage = 1;
let paginationItemsPerPage = 10;
let paginationAllUsers = [];
let paginationFilteredUsers = [];

// Initialize pagination
function initializePagination() {
    // Get all user rows that are actually rendered
    const userRows = document.querySelectorAll('tbody tr');
    
    // Filter out any non-data rows (headers, empty rows, etc.)
    const actualUserRows = Array.from(userRows).filter(row => {
        // Check if this row contains user data (has email, actions, etc.)
        const hasUsername = row.querySelector('td:nth-child(2)')?.textContent?.trim();
        const hasEmail = row.querySelector('td:nth-child(3)')?.textContent?.trim();
        const hasActions = row.querySelector('td:last-child button');
        return (hasUsername || hasEmail) && hasActions;
    });
    
    paginationAllUsers = actualUserRows;
    
    // Check if server-side pagination is already active
    const paginationInfo = document.querySelector('.pagination-info');
    const serverTotalText = paginationInfo?.textContent;
    
    // If server is already handling pagination (showing "X to Y of Z entries"), 
    // don't apply client-side pagination
    if (serverTotalText && serverTotalText.includes('of') && !serverTotalText.includes('visible')) {
        return;
    }
    
    // Get current page from URL or default to 1
    const urlParams = new URLSearchParams(window.location.search);
    paginationCurrentPage = parseInt(urlParams.get('page')) || 1;
    
    // Get items per page from dropdown or URL
    const entriesSelect = document.getElementById('entries');
    const limitFromUrl = urlParams.get('limit');
    
    if (limitFromUrl) {
        if (limitFromUrl === 'all' || limitFromUrl === 'All') {
            paginationItemsPerPage = 'all';
            if (entriesSelect) entriesSelect.value = 'all';
        } else {
            paginationItemsPerPage = parseInt(limitFromUrl) || 10;
            if (entriesSelect) entriesSelect.value = paginationItemsPerPage.toString();
        }
    } else {
        paginationItemsPerPage = parseInt(entriesSelect?.value) || 10;
    }
    
    
    // Apply pagination
    applyPagination();
    updatePaginationInfo();
    updatePaginationControls();
}

// Apply pagination to show/hide users
function applyPagination() {

    
    // If "all" is selected, show all users
    if (paginationItemsPerPage === 'all' || paginationItemsPerPage === 'All') {
        paginationAllUsers.forEach((row) => {
            row.style.display = '';
        });
        return;
    }
    
    const startIndex = (paginationCurrentPage - 1) * paginationItemsPerPage;
    const endIndex = startIndex + paginationItemsPerPage;
    
  
    let visibleCount = 0;
    
    // Hide all users first
    paginationAllUsers.forEach((row, index) => {
        if (index >= startIndex && index < endIndex) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
}

// Update pagination info text
function updatePaginationInfo() {
    const totalUsers = paginationAllUsers.length;
    const visibleUsers = paginationAllUsers.filter(row => row.style.display !== 'none').length;
    
    // Handle "all" case
    if (paginationItemsPerPage === 'all' || paginationItemsPerPage === 'All') {
        const infoElement = document.querySelector('.pagination-info');
        if (infoElement) {
            if (totalUsers > 0) {
                infoElement.textContent = `Showing all ${totalUsers} entries`;
            } else {
                infoElement.textContent = 'No entries to show';
            }
        }
        return;
    }
    
    const startEntry = totalUsers > 0 ? (paginationCurrentPage - 1) * paginationItemsPerPage + 1 : 0;
    const endEntry = Math.min(paginationCurrentPage * paginationItemsPerPage, totalUsers);
    
    const infoElement = document.querySelector('.pagination-info');
    if (infoElement) {
        if (totalUsers > 0) {
            infoElement.textContent = `Showing ${startEntry} to ${endEntry} of ${totalUsers} entries (${visibleUsers} visible)`;
        } else {
            infoElement.textContent = 'No entries to show';
        }
    }
}

// Update pagination controls
function updatePaginationControls() {
    const totalUsers = paginationAllUsers.length;
    
    // Handle "all" case - hide pagination controls
    if (paginationItemsPerPage === 'all' || paginationItemsPerPage === 'All') {
        const paginationNav = document.getElementById('paginationNav');
        if (paginationNav) {
            paginationNav.style.display = 'none';
        }
        return;
    }
    
    // Show pagination controls for normal pagination
    const paginationNav = document.getElementById('paginationNav');
    if (paginationNav) {
        paginationNav.style.display = 'block';
    }
    
    const totalPages = Math.ceil(totalUsers / paginationItemsPerPage);
    
    // Update Previous button
    const prevButton = document.querySelector('.pagination .page-item:first-child');
    if (prevButton) {
        if (paginationCurrentPage <= 1) {
            prevButton.classList.add('disabled');
            prevButton.querySelector('a')?.remove();
            if (!prevButton.querySelector('span')) {
                const span = document.createElement('span');
                span.className = 'page-link';
                span.textContent = 'Previous';
                prevButton.appendChild(span);
            }
        } else {
            prevButton.classList.remove('disabled');
            if (prevButton.querySelector('span')) {
                const a = document.createElement('a');
                a.className = 'page-link';
                a.textContent = 'Previous';
                a.href = '#';
                a.onclick = (e) => {
                    e.preventDefault();
                    goToPage(paginationCurrentPage - 1);
                };
                prevButton.replaceChild(a, prevButton.querySelector('span'));
            }
        }
    }
    
    // Update Next button
    const nextButton = document.querySelector('.pagination .page-item:last-child');
    if (nextButton) {
        if (paginationCurrentPage >= totalPages) {
            nextButton.classList.add('disabled');
            nextButton.querySelector('a')?.remove();
            if (!nextButton.querySelector('span')) {
                const span = document.createElement('span');
                span.className = 'page-link';
                span.textContent = 'Next';
                nextButton.appendChild(span);
            }
        } else {
            nextButton.classList.remove('disabled');
            if (nextButton.querySelector('span')) {
                const a = document.createElement('a');
                a.className = 'page-link';
                a.textContent = 'Next';
                a.href = '#';
                a.onclick = (e) => {
                    e.preventDefault();
                    goToPage(paginationCurrentPage + 1);
                };
                nextButton.replaceChild(a, nextButton.querySelector('span'));
            }
        }
    }
    
    // Update page numbers
    updatePageNumbers(totalPages);
}

// Update page number links
function updatePageNumbers(totalPages) {
    const pageNumbersContainer = document.querySelector('.pagination');
    if (!pageNumbersContainer) return;
    
    // Remove existing page numbers (keep Previous and Next buttons)
    const pageItems = pageNumbersContainer.querySelectorAll('.page-item:not(:first-child):not(:last-child)');
    pageItems.forEach(item => item.remove());
    
    // Generate page numbers
    const maxVisiblePages = 5;
    let startPage = Math.max(1, paginationCurrentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        const li = document.createElement('li');
        li.className = `page-item ${i === paginationCurrentPage ? 'active' : ''}`;
        
        const a = document.createElement('a');
        a.className = 'page-link';
        a.textContent = i;
        a.href = '#';
        a.onclick = (e) => {
            e.preventDefault();
            goToPage(i);
        };
        
        li.appendChild(a);
        pageNumbersContainer.insertBefore(li, pageNumbersContainer.lastElementChild);
    }
}

// Navigate to specific page
function goToPage(page) {
    paginationCurrentPage = page;
    applyPagination();
    updatePaginationInfo();
    updatePaginationControls();
    
    // Update URL without page reload
    const url = new URL(window.location);
    url.searchParams.set('page', page);
    window.history.pushState({}, '', url);
}

// Handle entries dropdown change
function handleEntriesChange() {
    const entriesSelect = document.getElementById('entries');
    if (entriesSelect) {
        entriesSelect.addEventListener('change', function() {
            const value = this.value;
            // Handle "all" option
            if (value === 'all' || value === 'All') {
                paginationItemsPerPage = 'all';
            } else {
                paginationItemsPerPage = parseInt(value) || 10;
            }
            paginationCurrentPage = 1; // Reset to first page
            applyPagination();
            updatePaginationInfo();
            updatePaginationControls();
            
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('limit', value);
            url.searchParams.set('page', '1');
            window.history.pushState({}, '', url);
        });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initializePagination();
    handleEntriesChange();
});
