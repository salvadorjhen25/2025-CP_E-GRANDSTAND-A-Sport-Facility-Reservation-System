/**
 * Enhanced Modal System for Facility Reservation System
 * Replaces all alert() and confirm() calls with beautiful modal dialogs
 */

class ModalSystem {
    constructor() {
        this.init();
    }

    init() {
        // Create modal container if it doesn't exist
        if (!document.getElementById('modal-container')) {
            this.createModalContainer();
        }
        
        // Add global styles
        this.addGlobalStyles();
        
        // Initialize event listeners
        this.initEventListeners();
    }

    createModalContainer() {
        // Safety check: ensure document.body exists
        if (!document.body) {
            console.warn('ModalSystem: document.body not available, retrying...');
            setTimeout(() => this.createModalContainer(), 100);
            return;
        }

        const modalContainer = document.createElement('div');
        modalContainer.id = 'modal-container';
        modalContainer.className = 'fixed inset-0 z-50 hidden';
        modalContainer.innerHTML = `
            <div class="modal-backdrop fixed inset-0 bg-black bg-opacity-50 transition-opacity duration-300"></div>
            <div class="modal-wrapper flex items-center justify-center min-h-screen p-4">
                <div class="modal-content bg-white rounded-lg shadow-xl max-w-md w-full transform transition-all duration-300 scale-95 opacity-0">
                    <div class="modal-header p-6 border-b border-gray-200">
                        <div class="flex items-center">
                            <div class="modal-icon mr-3"></div>
                            <h3 class="modal-title text-lg font-semibold text-gray-900"></h3>
                        </div>
                        <button class="modal-close absolute top-4 right-4 text-gray-400 hover:text-gray-600 transition duration-200">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    <div class="modal-body p-6">
                        <p class="modal-message text-gray-700"></p>
                    </div>
                    <div class="modal-footer p-6 border-t border-gray-200 bg-gray-50 rounded-b-lg">
                        <div class="modal-buttons flex justify-end space-x-3"></div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modalContainer);
    }

    addGlobalStyles() {
        const style = document.createElement('style');
        style.textContent = `
            .modal-content.show {
                transform: scale(100%) !important;
                opacity: 1 !important;
            }
            
            .modal-backdrop.show {
                opacity: 1 !important;
            }
            
            .modal-container.show {
                display: block !important;
            }
            
            .modal-button {
                @apply px-4 py-2 rounded-lg font-medium transition duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2;
            }
            
            .modal-button-primary {
                @apply bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500;
            }
            
            .modal-button-secondary {
                @apply bg-gray-300 text-gray-700 hover:bg-gray-400 focus:ring-gray-500;
            }
            
            .modal-button-danger {
                @apply bg-red-600 text-white hover:bg-red-700 focus:ring-red-500;
            }
            
            .modal-button-success {
                @apply bg-green-600 text-white hover:bg-green-700 focus:ring-green-500;
            }
            
            .modal-button-warning {
                @apply bg-yellow-600 text-white hover:bg-yellow-700 focus:ring-yellow-500;
            }
            
            .modal-icon {
                @apply w-8 h-8 rounded-full flex items-center justify-center;
            }
            
            .modal-icon.info {
                @apply bg-blue-100 text-blue-600;
            }
            
            .modal-icon.success {
                @apply bg-green-100 text-green-600;
            }
            
            .modal-icon.warning {
                @apply bg-yellow-100 text-yellow-600;
            }
            
            .modal-icon.error {
                @apply bg-red-100 text-red-600;
            }
            
            .modal-icon.question {
                @apply bg-purple-100 text-purple-600;
            }
            
            @keyframes modalSlideIn {
                from {
                    transform: translateY(-20px) scale(0.95);
                    opacity: 0;
                }
                to {
                    transform: translateY(0) scale(1);
                    opacity: 1;
                }
            }
            
            .modal-slide-in {
                animation: modalSlideIn 0.3s ease-out;
            }
        `;
        document.head.appendChild(style);
    }

    initEventListeners() {
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-close') || e.target.closest('.modal-close')) {
                this.hide();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.hide();
            }
        });

        // Close modal when clicking backdrop
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-backdrop')) {
                this.hide();
            }
        });
    }

    show(options = {}) {
        const {
            title = 'Notification',
            message = '',
            type = 'info', // info, success, warning, error, question
            buttons = [],
            onConfirm = null,
            onCancel = null,
            showClose = true,
            closeOnBackdrop = true,
            closeOnEscape = true
        } = options;

        const modalContainer = document.getElementById('modal-container');
        const modalContent = modalContainer.querySelector('.modal-content');
        const modalBackdrop = modalContainer.querySelector('.modal-backdrop');
        const modalTitle = modalContainer.querySelector('.modal-title');
        const modalMessage = modalContainer.querySelector('.modal-message');
        const modalIcon = modalContainer.querySelector('.modal-icon');
        const modalButtons = modalContainer.querySelector('.modal-buttons');
        const closeButton = modalContainer.querySelector('.modal-close');

        // Set content
        modalTitle.textContent = title;
        modalMessage.textContent = message;
        
        // Set icon
        modalIcon.className = `modal-icon ${type}`;
        modalIcon.innerHTML = this.getIconForType(type);

        // Set buttons
        modalButtons.innerHTML = '';
        buttons.forEach(button => {
            const btn = document.createElement('button');
            btn.className = `modal-button ${button.class || 'modal-button-primary'}`;
            btn.textContent = button.text;
            btn.onclick = () => {
                if (button.onClick) button.onClick();
                this.hide();
            };
            modalButtons.appendChild(btn);
        });

        // Show/hide close button
        closeButton.style.display = showClose ? 'block' : 'none';

        // Show modal
        modalContainer.classList.add('show');
        modalBackdrop.classList.add('show');
        modalContent.classList.add('show', 'modal-slide-in');

        // Store callbacks
        this.currentModal = {
            onConfirm,
            onCancel,
            closeOnBackdrop,
            closeOnEscape
        };

        // Focus first button
        setTimeout(() => {
            const firstButton = modalButtons.querySelector('button');
            if (firstButton) firstButton.focus();
        }, 100);
    }

    hide() {
        const modalContainer = document.getElementById('modal-container');
        const modalContent = modalContainer.querySelector('.modal-content');
        const modalBackdrop = modalContainer.querySelector('.modal-backdrop');

        modalContent.classList.remove('show', 'modal-slide-in');
        modalBackdrop.classList.remove('show');
        
        setTimeout(() => {
            modalContainer.classList.remove('show');
        }, 300);

        // Clear current modal
        this.currentModal = null;
    }

    getIconForType(type) {
        const icons = {
            info: '<i class="fas fa-info-circle"></i>',
            success: '<i class="fas fa-check-circle"></i>',
            warning: '<i class="fas fa-exclamation-triangle"></i>',
            error: '<i class="fas fa-times-circle"></i>',
            question: '<i class="fas fa-question-circle"></i>'
        };
        return icons[type] || icons.info;
    }

    // Convenience methods
    alert(message, title = 'Information', type = 'info') {
        return new Promise((resolve) => {
            this.show({
                title,
                message,
                type,
                buttons: [{
                    text: 'OK',
                    class: 'modal-button-primary',
                    onClick: () => resolve(true)
                }]
            });
        });
    }

    confirm(message, title = 'Confirmation', type = 'question') {
        return new Promise((resolve) => {
            this.show({
                title,
                message,
                type,
                buttons: [
                    {
                        text: 'Cancel',
                        class: 'modal-button-secondary',
                        onClick: () => resolve(false)
                    },
                    {
                        text: 'Confirm',
                        class: 'modal-button-primary',
                        onClick: () => resolve(true)
                    }
                ]
            });
        });
    }

    prompt(message, title = 'Input Required', defaultValue = '', type = 'info') {
        return new Promise((resolve) => {
            const modalContainer = document.getElementById('modal-container');
            const modalBody = modalContainer.querySelector('.modal-body');
            
            // Create input field
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500';
            input.value = defaultValue;
            input.placeholder = 'Enter your input...';
            
            modalBody.innerHTML = '';
            modalBody.appendChild(document.createElement('p')).textContent = message;
            modalBody.appendChild(input);

            this.show({
                title,
                type,
                buttons: [
                    {
                        text: 'Cancel',
                        class: 'modal-button-secondary',
                        onClick: () => resolve(null)
                    },
                    {
                        text: 'OK',
                        class: 'modal-button-primary',
                        onClick: () => resolve(input.value)
                    }
                ]
            });

            // Focus input
            setTimeout(() => input.focus(), 100);
        });
    }

    success(message, title = 'Success') {
        return this.alert(message, title, 'success');
    }

    error(message, title = 'Error') {
        return this.alert(message, title, 'error');
    }

    warning(message, title = 'Warning') {
        return this.alert(message, title, 'warning');
    }

    info(message, title = 'Information') {
        return this.alert(message, title, 'info');
    }

    // Loading modal
    showLoading(message = 'Loading...', title = 'Please Wait') {
        const modalContainer = document.getElementById('modal-container');
        const modalBody = modalContainer.querySelector('.modal-body');
        const modalButtons = modalContainer.querySelector('.modal-buttons');
        
        modalBody.innerHTML = `
            <div class="flex items-center justify-center py-4">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mr-3"></div>
                <span class="text-gray-700">${message}</span>
            </div>
        `;
        modalButtons.innerHTML = '';

        this.show({
            title,
            message: '',
            type: 'info',
            showClose: false,
            closeOnBackdrop: false,
            closeOnEscape: false
        });
    }

    hideLoading() {
        this.hide();
    }

    // Form validation modal
    showValidationErrors(errors, title = 'Validation Errors') {
        const errorList = errors.map(error => `<li class="text-red-600">• ${error}</li>`).join('');
        
        return this.show({
            title,
            message: `<ul class="list-disc list-inside space-y-1">${errorList}</ul>`,
            type: 'error',
            buttons: [{
                text: 'OK',
                class: 'modal-button-danger'
            }]
        });
    }

    // Custom form modal
    showForm(formConfig) {
        return new Promise((resolve) => {
            const modalContainer = document.getElementById('modal-container');
            const modalBody = modalContainer.querySelector('.modal-body');
            
            // Create form
            const form = document.createElement('form');
            form.className = 'space-y-4';
            
            formConfig.fields.forEach(field => {
                const fieldDiv = document.createElement('div');
                const label = document.createElement('label');
                label.className = 'block text-sm font-medium text-gray-700 mb-1';
                label.textContent = field.label;
                
                const input = document.createElement(field.type === 'textarea' ? 'textarea' : 'input');
                input.type = field.type || 'text';
                input.name = field.name;
                input.className = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500';
                input.required = field.required || false;
                
                if (field.placeholder) input.placeholder = field.placeholder;
                if (field.value) input.value = field.value;
                if (field.type === 'textarea') input.rows = field.rows || 3;
                
                fieldDiv.appendChild(label);
                fieldDiv.appendChild(input);
                form.appendChild(fieldDiv);
            });

            modalBody.innerHTML = '';
            modalBody.appendChild(form);

            this.show({
                title: formConfig.title,
                type: formConfig.type || 'info',
                buttons: [
                    {
                        text: 'Cancel',
                        class: 'modal-button-secondary',
                        onClick: () => resolve(null)
                    },
                    {
                        text: 'Submit',
                        class: 'modal-button-primary',
                        onClick: () => {
                            const formData = new FormData(form);
                            const data = {};
                            for (let [key, value] of formData.entries()) {
                                data[key] = value;
                            }
                            resolve(data);
                        }
                    }
                ]
            });
        });
    }
}

// Initialize modal system globally after DOM is loaded
function initializeModalSystem() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            window.ModalSystem = new ModalSystem();
        });
    } else {
        window.ModalSystem = new ModalSystem();
    }
}

// Initialize immediately if possible, otherwise wait for DOM
initializeModalSystem();

// Replace native alert and confirm after initialization
function setupGlobalOverrides() {
    if (window.ModalSystem) {
        window.originalAlert = window.alert;
        window.originalConfirm = window.confirm;

        window.alert = function(message) {
            return window.ModalSystem.alert(message);
        };

        window.confirm = function(message) {
            return window.ModalSystem.confirm(message);
        };
    } else {
        // If ModalSystem isn't ready yet, wait a bit and try again
        setTimeout(setupGlobalOverrides, 100);
    }
}

// Setup global overrides
setupGlobalOverrides();
