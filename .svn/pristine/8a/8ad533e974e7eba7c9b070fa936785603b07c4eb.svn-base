/**
 * Cloud Auto Importer - Admin JavaScript
 *
 * @package     CloudAutoImporter
 * @author      Michael Cloud Auto Importer
 * @license     GPL-2.0+
 * @version     1.0.0
 */

(function($) {
    'use strict';
    
    // Main plugin instance
    var CloudAutoImporter = {
        // Configuration
        config: {
            ajaxurl: '',
            nonce: '',
            debug: false,
            upload_url: ''
        },
        
        // Runtime state
        state: {
            currentImportId: null,
            isImporting: false,
            pollInterval: null,
            pollFrequency: 3000,
            isPaused: false,
            batchInProgress: false,
            initialized: false
        },
        
        // Initialize the plugin
        init: function() {
            // Prevent multiple initializations
            if (this.state.initialized) {
                return;
            }
            
            // Load configuration from localized script
            this.loadConfig();
            
            // Initialize UI components
            this.initUI();
            
            // Bind events
            this.bindEvents();
            
            // Check for existing imports
            this.checkExistingImports();
            
            this.state.initialized = true;
            
            if (this.config.debug) {
                console.log('Cloud Auto Importer JS v1.0.0 initialized');
            }
        },
        
        // Load configuration from localized script
        loadConfig: function() {
            if (typeof window.mcai_ajax !== 'undefined') {
                this.config.ajaxurl = window.mcai_ajax.ajax_url || '';
                this.config.nonce = window.mcai_ajax.nonce || '';
                this.config.debug = window.mcai_ajax.debug || false;
                this.config.upload_url = window.mcai_ajax.upload_url || '';
                this.config.i18n = window.mcai_ajax.i18n || {};
            } else {
                console.error(this._t('ajax_object_undefined'));
                this.config.ajaxurl = window.ajaxurl || '/wp-admin/admin-ajax.php';
                this.config.i18n = {};
            }
        },
        
        // Get translated string
        _t: function(key, fallback) {
            if (this.config.i18n && this.config.i18n[key]) {
                return this.config.i18n[key];
            }
            return fallback || key;
        },
        
        // Initialize UI components
        initUI: function() {
            if (this.config.debug) {
                console.log('Initializing UI components...');
            }
            
            // Initialize compression toggle
            this.initCompressionToggle();
            
            // Initialize quality slider
            this.initQualitySlider();
            
            // Initialize tooltips
            this.initTooltips();
            
            // Initialize privacy details toggle
            this.initPrivacyToggle();
        },
        
        // Initialize compression toggle
        initCompressionToggle: function() {
            var self = this;
            
            // Use event delegation
            $(document).on('change', '#mcai_compress_images', function() {
                if ($(this).is(':checked')) {
                    $('#mcai-compression-options').slideDown(200);
                } else {
                    $('#mcai-compression-options').slideUp(200);
                }
            });
            
            // Set initial state
            if ($('#mcai_compress_images').is(':checked')) {
                $('#mcai-compression-options').show();
            } else {
                $('#mcai-compression-options').hide();
            }
        },
        
        // Initialize quality slider
        initQualitySlider: function() {
            var $slider = $('#mcai-quality-slider');
            if ($slider.length && $.fn.slider) {
                $slider.slider({
                    range: "min",
                    value: parseInt($('#mcai_compression_quality').val(), 10) || 80,
                    min: 50,
                    max: 95,
                    slide: function(event, ui) {
                        $('#mcai-quality-value').text(ui.value + '%');
                        $('#mcai_compression_quality').val(ui.value);
                    }
                });
            }
        },
        
        // Initialize tooltips
        initTooltips: function() {
            // Remove existing tooltips first
            $('.mcai-tooltip').off('mouseenter mouseleave');
            
            // Create tooltip container
            if ($('#mcai-tooltip-container').length === 0) {
                $('body').append('<div id="mcai-tooltip-container" class="mcai-tooltip-container"></div>');
            }
            
            $(document).on('mouseenter', '.mcai-tooltip', function(e) {
                var tip = $(this).data('tip');
                if (tip) {
                    var $tooltip = $('<div class="mcai-tooltip-bubble">' + tip + '</div>');
                    $('#mcai-tooltip-container').html($tooltip);
                    
                    var offset = $(this).offset();
                    $tooltip.css({
                        top: offset.top - $tooltip.outerHeight() - 10,
                        left: offset.left + ($(this).outerWidth() / 2) - ($tooltip.outerWidth() / 2)
                    }).addClass('visible');
                }
            }).on('mouseleave', '.mcai-tooltip', function() {
                $('#mcai-tooltip-container').empty();
            });
        },
        
        // Initialize privacy details toggle
        initPrivacyToggle: function() {
            // Remove existing handlers
            $('#mcai-show-privacy').off('click');
            
            // Add new handler
            $('#mcai-show-privacy').on('click', function(e) {
                e.preventDefault();
                
                var $privacyDetails = $('#mcai-privacy-details');
                var isVisible = $privacyDetails.is(':visible');
                
                if (isVisible) {
                    $privacyDetails.slideUp(300);
                    $(this).text(CloudAutoImporter._t('view_privacy_details', 'View privacy details'));
                } else {
                    $privacyDetails.slideDown(300);
                    $(this).text(CloudAutoImporter._t('hide_privacy_details', 'Hide privacy details'));
                }
            });
            
            // Initialize state
            $('#mcai-privacy-details').hide();
            $('#mcai-show-privacy').text(this._t('view_privacy_details', 'View privacy details'));
        },
        
        // Bind all event handlers
        bindEvents: function() {
            var self = this;
            
            // Import form submission
            $(document).on('submit', '#mcai-import-form', function(e) {
                self.handleImportStart.call(self, e);
            });
            
            // Import controls
            $(document).on('click', '#mcai-pause-import', function() {
                self.pauseImport.call(self);
            });
            
            $(document).on('click', '#mcai-resume-import', function() {
                self.resumeImport.call(self);
            });
            
            $(document).on('click', '#mcai-cancel-import', function() {
                self.cancelImport.call(self);
            });
            
            $(document).on('click', '#mcai-view-results', function() {
                self.viewResults.call(self);
            });
        },
        
        // Handle import form submission
        handleImportStart: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var $spinner = $('#mcai-import-spinner');
            var folderUrl = $form.find('#cloud_folder_url').val().trim();
            
            // Validation
            if (!folderUrl) {
                this.showNotification(this._t('enter_drive_url', 'Please enter a Google Drive folder URL'), 'error');
                return false;
            }
            
            if (!folderUrl.includes('drive.google.com')) {
                this.showNotification(this._t('valid_drive_url', 'Please enter a valid Google Drive URL'), 'error');
                return false;
            }
            
            // Disable form and show loading
            $form.find('input, button, select').prop('disabled', true);
            $submitBtn.html('<span class="dashicons dashicons-update"></span> ' + 
                           this._t('starting_import', 'Starting Import...'));
            $spinner.show();
            
            // Prepare form data
            var formData = {
                action: 'mcai_start_import',
                nonce: this.config.nonce,
                cloud_folder_url: folderUrl,
                compress_images: $('#mcai_compress_images').is(':checked') ? '1' : '0',
                skip_duplicates: $('#mcai_skip_duplicates').is(':checked') ? '1' : '0',
                generate_alt_text: $('#mcai_generate_alt_text').is(':checked') ? '1' : '0',
                batch_size: $('#mcai_batch_size').val() || '25'
            };
            
            // Add compression quality if enabled
            if ($('#mcai_compress_images').is(':checked')) {
                formData.compression_quality = $('#mcai_compression_quality').val() || '80';
            }
            
            if (this.config.debug) {
                console.log('Starting import with data:', formData);
            }
            
            // Send AJAX request
            $.ajax({
                url: this.config.ajaxurl,
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    self.handleImportResponse.call(self, response, $form, $submitBtn, $spinner);
                },
                error: function(xhr, status, error) {
                    self.handleAjaxError.call(self, xhr, status, error, $form, $submitBtn, $spinner);
                }
            });
            
            return false;
        },
        
        // Handle import response
        handleImportResponse: function(response, $form, $submitBtn, $spinner) {
            if (!response || typeof response !== 'object') {
                this.showNotification(this._t('invalid_response', 'Invalid response received from server'), 'error');
                this.resetFormUI($form, $submitBtn, $spinner);
                return;
            }
            
            if (this.config.debug) {
                console.log('Import response:', response);
            }
            
            if (response.success) {
                var message = response.data && response.data.message ? 
                    response.data.message : this._t('import_started', 'Import started!');
                
                this.showNotification(message, 'success');
                
                if (response.data && response.data.import_id) {
                    this.startImportProgress(response.data.import_id, response.data.total_files);
                    $form.find('#mcai-current-import-id').val(response.data.import_id);
                } else {
                    this.showNotification(this._t('no_import_id', 'Import started but no ID returned'), 'warning');
                    this.resetFormUI($form, $submitBtn, $spinner);
                }
            } else {
                var errorMsg = response.data && response.data.message ? 
                    response.data.message : this._t('error_occurred', 'An error occurred');
                
                this.showNotification(errorMsg, 'error');
                this.resetFormUI($form, $submitBtn, $spinner);
            }
        },
        
        // Handle AJAX error
        handleAjaxError: function(xhr, status, error, $form, $submitBtn, $spinner) {
            var errorMessage = this._t('ajax_error', 'AJAX request failed');
            
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage = xhr.responseJSON.data.message;
            } else if (error) {
                errorMessage += ': ' + error;
            }
            
            this.showNotification(errorMessage, 'error');
            
            if (this.config.debug) {
                console.error('AJAX Error:', status, error, xhr.responseText);
            }
            
            this.resetFormUI($form, $submitBtn, $spinner);
        },
        
        // Reset form UI
        resetFormUI: function($form, $submitBtn, $spinner) {
            $form.find('input, button, select').prop('disabled', false);
            $submitBtn.html('<span class="dashicons dashicons-cloud-upload"></span> ' + 
                           this._t('start_import', 'Start Import'));
            $spinner.hide();
        },
        
        // Show notification
        showNotification: function(message, type) {
            // Remove existing notifications
            $('.mcai-notification').remove();
            
            var $notification = $(
                '<div class="mcai-notification notice notice-' + type + ' is-dismissible">' +
                '<p>' + $('<div>').text(message).html() + '</p>' +
                '<button type="button" class="notice-dismiss">' +
                '<span class="screen-reader-text">' + 
                this._t('dismiss_notice', 'Dismiss this notice.') + 
                '</span>' +
                '</button>' +
                '</div>'
            );
            
            // Add to page
            $('.wrap h1').first().after($notification);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Make dismissible
            $notification.on('click', '.notice-dismiss', function() {
                $(this).closest('.mcai-notification').remove();
            });
        },
        
        // Check for existing imports
        checkExistingImports: function() {
            var importId = $('#mcai-current-import-id').val();
            if (importId) {
                if (this.config.debug) {
                    console.log('Found active import:', importId);
                }
                this.startImportProgress(importId, 0);
            }
        },
        
        // Start import progress tracking
        startImportProgress: function(importId, totalFiles) {
            if (!importId) {
                return;
            }
            
            // Reset state
            this.state.currentImportId = importId;
            this.state.isImporting = true;
            this.state.isPaused = false;
            this.state.batchInProgress = false;
            
            // Show progress container
            $('#mcai-progress-container').show().removeClass('completed');
            
            // Update UI
            this.updateProgressUI(0, this._t('processing', 'Initializing import...'), 0, totalFiles);
            this.updateStatsDisplay();
            
            // Start polling
            var self = this;
            setTimeout(function() {
                self.state.pollInterval = setInterval(function() {
                    self.pollImportStatus.call(self);
                }, self.state.pollFrequency);
            }, 1000);
            
            // Show controls
            $('#mcai-pause-import').show();
            $('#mcai-resume-import').hide();
            $('#mcai-cancel-import').show();
            $('#mcai-import-id-display').text('ID: ' + importId.substring(0, 12) + '...');
            
            // Start processing
            setTimeout(function() {
                if (self.state.isImporting && !self.state.isPaused) {
                    self.processNextBatch.call(self, importId);
                }
            }, 1500);
        },
        
        // Poll import status
        pollImportStatus: function() {
            if (!this.state.currentImportId || !this.state.isImporting || 
                this.state.isPaused || this.state.batchInProgress) {
                return;
            }
            
            var self = this;
            
            $.ajax({
                url: this.config.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mcai_get_import_status',
                    nonce: this.config.nonce,
                    import_id: this.state.currentImportId
                },
                dataType: 'json',
                success: function(response) {
                    if (!response || typeof response !== 'object') {
                        return;
                    }
                    
                    if (response.success && response.data) {
                        self.handleStatusResponse.call(self, response.data);
                    }
                },
                error: function(xhr, status, error) {
                    if (self.config.debug) {
                        console.error('Polling error:', error);
                    }
                }
            });
        },
        
        // Handle status response
        handleStatusResponse: function(data) {
            if (data.progress) {
                this.updateProgressUI(
                    data.progress.percentage,
                    this._t('processed_files', 'Processed:') + ' ' + 
                    data.progress.processed + '/' + data.progress.total + ' ' + 
                    this._t('files', 'files'),
                    data.progress.processed,
                    data.progress.total
                );
                
                this.updateStatsDisplay(
                    data.progress.successful || 0,
                    data.progress.failed || 0,
                    data.progress.skipped || 0
                );
                
                if (!data.completed && 
                    data.progress.current < data.progress.total && 
                    !this.state.batchInProgress) {
                    
                    this.processNextBatch(this.state.currentImportId);
                }
            }
            
            if (data.completed) {
                this.completeImport(data);
            }
        },
        
        // Process next batch
        processNextBatch: function(importId) {
            if (!importId || this.state.isPaused || this.state.batchInProgress) {
                return;
            }
            
            this.state.batchInProgress = true;
            
            var self = this;
            var batchSize = $('#mcai_batch_size').val() || 25;
            
            $.ajax({
                url: this.config.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mcai_process_batch',
                    nonce: this.config.nonce,
                    import_id: importId,
                    batch_size: batchSize
                },
                dataType: 'json',
                success: function(response) {
                    if (!response || typeof response !== 'object') {
                        self.state.batchInProgress = false;
                        return;
                    }
                    
                    if (response.success && response.data) {
                        if (response.data.batch_results) {
                            self.updateStatsDisplay(
                                response.data.batch_results.successful || 0,
                                response.data.batch_results.failed || 0,
                                response.data.batch_results.skipped || 0
                            );
                        }
                        
                        if (response.data.progress) {
                            self.updateProgressUI(
                                response.data.progress.percentage,
                                self._t('processing', 'Processing...'),
                                response.data.progress.processed,
                                response.data.progress.total
                            );
                        }
                        
                        if (response.data.completed) {
                            self.completeImport(response.data);
                        }
                    }
                    self.state.batchInProgress = false;
                },
                error: function(xhr, status, error) {
                    if (self.config.debug) {
                        console.error('Batch processing failed:', error);
                    }
                    self.state.batchInProgress = false;
                }
            });
        },
        
        // Update progress UI
        updateProgressUI: function(percentage, message, processed, total) {
            percentage = Math.min(100, Math.max(0, percentage));
            $('.mcai-progress-fill').css('width', percentage + '%');
            $('#mcai-progress-percentage').text(percentage + '%');
            $('#mcai-progress-details').text(message);
            
            if (processed !== undefined && total !== undefined) {
                $('#mcai-processed-files').text(processed);
                $('#mcai-total-files').text(total);
            }
        },
        
        // Update stats display
        updateStatsDisplay: function(successful, failed, skipped) {
            if (successful !== undefined) {
                $('#mcai-successful-count').text(successful);
            }
            if (failed !== undefined) {
                $('#mcai-failed-count').text(failed);
            }
            if (skipped !== undefined) {
                $('#mcai-skipped-count').text(skipped);
            }
        },
        
        // Complete import process
        completeImport: function(response) {
            clearInterval(this.state.pollInterval);
            
            this.state.isImporting = false;
            this.state.isPaused = false;
            this.state.batchInProgress = false;
            
            var successful = response.progress ? response.progress.successful : 0;
            var total = response.progress ? response.progress.total : 0;
            
            this.updateProgressUI(100, this._t('import_complete', 'Import completed!'), total, total);
            
            var completeMessage = this._t('import_complete_message', 'Import complete!') + ' ' + 
                                 successful + ' ' + 
                                 this._t('images_imported', 'images imported successfully.');
            
            this.showNotification(completeMessage, 'success');
            
            // Update UI
            $('#mcai-progress-container').addClass('completed');
            $('#mcai-pause-import, #mcai-resume-import').hide();
            $('#mcai-view-results').show();
            
            // Reset form
            $('#mcai-import-form')[0].reset();
            $('#mcai_compress_images').trigger('change');
            
            // Clear import ID
            $('#mcai-current-import-id').val('');
            this.state.currentImportId = null;
            
            // Reload page after delay
            var self = this;
            setTimeout(function() {
                window.location.reload();
            }, 5000);
        },
        
        // Pause import
        pauseImport: function() {
            this.state.isPaused = true;
            clearInterval(this.state.pollInterval);
            
            this.showNotification(this._t('import_paused', 'Import paused'), 'warning');
            
            $('#mcai-pause-import').hide();
            $('#mcai-resume-import').show();
        },
        
        // Resume import
        resumeImport: function() {
            if (!this.state.currentImportId) {
                return;
            }
            
            this.state.isPaused = false;
            
            var self = this;
            this.state.pollInterval = setInterval(function() {
                self.pollImportStatus.call(self);
            }, this.state.pollFrequency);
            
            this.showNotification(this._t('import_resumed', 'Import resumed'), 'info');
            
            $('#mcai-resume-import').hide();
            $('#mcai-pause-import').show();
            
            // Resume processing
            if (!this.state.batchInProgress) {
                this.processNextBatch(this.state.currentImportId);
            }
        },
        
        // Cancel import
        cancelImport: function() {
            var confirmMessage = this._t('cancel_confirm', 'Are you sure you want to cancel this import?');
            
            if (confirm(confirmMessage)) {
                clearInterval(this.state.pollInterval);
                
                this.state.isImporting = false;
                this.state.isPaused = false;
                this.state.batchInProgress = false;
                this.state.currentImportId = null;
                
                $('#mcai-progress-container').fadeOut(300);
                this.showNotification(this._t('import_cancelled', 'Import cancelled'), 'warning');
                
                // Reset form
                var $form = $('#mcai-import-form');
                $form.find('input, button, select').prop('disabled', false);
                $form.find('button[type="submit"]').html(
                    '<span class="dashicons dashicons-cloud-upload"></span> ' + 
                    this._t('start_import', 'Start Import')
                );
                $('#mcai-import-spinner').hide();
                
                // Clear import ID
                $('#mcai-current-import-id').val('');
            }
        },
        
        // View results
        viewResults: function() {
            if (this.config.upload_url) {
                window.location.href = this.config.upload_url;
            } else {
                window.location.href = '/wp-admin/upload.php';
            }
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        CloudAutoImporter.init();
    });
    
    // Make available globally if needed
    window.CloudAutoImporter = CloudAutoImporter;
    
})(jQuery);