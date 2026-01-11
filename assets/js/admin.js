jQuery(document).ready(function($) {
    'use strict';
    
    console.log('Gallery Admin JS Loaded - Chrome Double Popup FIXED');
    
    // Variables
    var dropZone = $('#gp-drop-zone');
    var fileInput = $('#gp-file-input');
    var imagesGrid = $('#gp-images-grid');
    var imageCount = $('#gp-image-count');
    var uploadStatus = $('.gp-upload-status');
    var isFileDialogOpen = false; // Chrome FIX
    
    // Initialize
    initUploader();
    initImageRemoval();
    initCopyShortcode();
    
    // 1. UPLOADER INITIALIZATION - CHROME ULTIMATE FIX
    function initUploader() {
        if (dropZone.length === 0) return;
        
        // FIX: Use direct click handler instead of delegation
        $('.gp-browse').off('click').on('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation(); // IMPORTANT: Chrome fix
            
            console.log('Browse button clicked');
            
            // Prevent multiple clicks
            if (isFileDialogOpen) {
                console.log('File dialog already open, ignoring');
                return false;
            }
            
            isFileDialogOpen = true;
            
            // Simple reset
            fileInput.val('');
            
            // Use native click with timeout
            setTimeout(function() {
                fileInput[0].click();
            }, 0);
            
            return false;
        });
        
        // FIX: Simple file input handler
        fileInput.off('change').on('change', function(e) {
            console.log('File input changed');
            
            var files = this.files;
            if (files && files.length > 0) {
                console.log(files.length + ' files selected');
                processFiles(files);
            }
            
            // Reset flag
            setTimeout(function() {
                isFileDialogOpen = false;
                $(this).val('');
            }.bind(this), 100);
        });
        
        // Drag & Drop - Keep as is
        dropZone.on('dragenter dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('highlight');
        });
        
        dropZone.on('dragleave drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('highlight');
        });
        
        dropZone.on('drop', function(e) {
            var originalEvent = e.originalEvent;
            var files = originalEvent.dataTransfer.files;
            
            if (files.length > 0) {
                processFiles(files);
            }
        });
        
        // FIX: Prevent drop zone click from triggering file input
        dropZone.on('click', function(e) {
            // Only allow clicks on the browse button
            if (!$(e.target).closest('.gp-browse').length) {
                e.stopPropagation();
            }
        });
    }
    
    // 2. PROCESS FILES - SIMPLIFIED
    function processFiles(files) {
        console.log('Processing ' + files.length + ' files');
        
        var validFiles = [];
        
        // Simple validation
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            
            if (file.type.match('image.*') && file.size <= gpAjax.max_size) {
                validFiles.push(file);
            }
        }
        
        if (validFiles.length > 0) {
            // Show uploading
            var originalContent = dropZone.html();
            dropZone.html('<div style="padding:20px; text-align:center;">' +
                         '<span class="dashicons dashicons-update" style="animation:spin 1s linear infinite; font-size:40px;"></span>' +
                         '<p>Uploading ' + validFiles.length + ' image(s)...</p>' +
                         '</div>');
            
            // Upload all files
            uploadAllFiles(validFiles, originalContent);
        }
    }
    
    // 3. UPLOAD ALL FILES AT ONCE
    function uploadAllFiles(files, originalContent) {
        var uploadedCount = 0;
        var totalFiles = files.length;
        
        files.forEach(function(file) {
            var formData = new FormData();
            formData.append('file', file);
            formData.append('action', 'gp_upload_image');
            formData.append('nonce', gpAjax.nonce);
            
            $.ajax({
                url: gpAjax.ajax_url,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(response) {
                    uploadedCount++;
                    
                    if (response.success) {
                        addImageToGrid(response.data.url, response.data.id);
                    }
                    
                    // When all uploaded
                    if (uploadedCount === totalFiles) {
                        setTimeout(function() {
                            dropZone.html(originalContent);
                            showUploadSuccess(totalFiles + ' images uploaded successfully!');
                            initUploader(); // Re-initialize
                        }, 500);
                    }
                },
                error: function() {
                    uploadedCount++;
                    console.error('Upload failed for:', file.name);
                    
                    if (uploadedCount === totalFiles) {
                        dropZone.html(originalContent);
                        initUploader();
                    }
                }
            });
        });
    }
    
    // 4. ADD IMAGE TO GRID
    function addImageToGrid(imageUrl, imageId) {
        var currentCount = imagesGrid.find('.gp-image-item').length;
        
        // Use template if exists, otherwise create manually
        var template = $('#gp-image-template').html();
        var html;
        
        if (template) {
            html = template
                .replace(/{index}/g, currentCount)
                .replace(/{url}/g, imageUrl)
                .replace(/{number}/g, currentCount + 1);
        } else {
            html = '<div class="gp-image-item" data-index="' + currentCount + '">' +
                   '<img src="' + imageUrl + '" alt="Image ' + (currentCount + 1) + '">' +
                   '<input type="hidden" name="existing_images[]" value="' + imageUrl + '">' +
                   '<button type="button" class="gp-remove-image" title="Remove image">' +
                   '<span class="dashicons dashicons-no"></span>' +
                   '</button>' +
                   '<span class="gp-image-number">' + (currentCount + 1) + '</span>' +
                   '</div>';
        }
        
        imagesGrid.append(html);
        updateImageCount();
        
        // Remove "no images" message
        $('.no-images-message').remove();
    }
    
    // 5. IMAGE REMOVAL
    function initImageRemoval() {
        $(document).on('click', '.gp-remove-image', function(e) {
            e.preventDefault();
            
            if (confirm('Remove this image?')) {
                var $item = $(this).closest('.gp-image-item');
                $item.fadeOut(300, function() {
                    $(this).remove();
                    updateImageCount();
                    updateImageNumbers();
                    
                    if (imagesGrid.find('.gp-image-item').length === 0) {
                        imagesGrid.append('<div class="no-images-message">' +
                                         '<p>No images uploaded yet.</p>' +
                                         '</div>');
                    }
                });
            }
        });
    }
    
    // 6. UPDATE FUNCTIONS
    function updateImageCount() {
        var count = imagesGrid.find('.gp-image-item').length;
        imageCount.text(count);
    }
    
    function updateImageNumbers() {
        imagesGrid.find('.gp-image-item').each(function(index) {
            $(this).find('.gp-image-number').text(index + 1);
        });
    }
    
    // 7. COPY SHORTCODE
    function initCopyShortcode() {
        $('.gp-copy-shortcode').on('click', function(e) {
            e.preventDefault();
            var shortcode = $(this).data('shortcode');
            
            // Copy to clipboard
            var temp = document.createElement('textarea');
            temp.value = shortcode;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            
            alert('Shortcode copied!');
        });
    }
    
    // 8. UPLOAD MESSAGES
    function showUploadSuccess(message) {
        if (uploadStatus.length === 0) return;
        
        uploadStatus
            .removeClass('error')
            .addClass('success')
            .html('<span class="dashicons dashicons-yes-alt"></span> ' + message)
            .show()
            .delay(3000)
            .fadeOut(1000);
    }
    
    function showUploadError(message) {
        if (uploadStatus.length === 0) return;
        
        uploadStatus
            .removeClass('success')
            .addClass('error')
            .html('<span class="dashicons dashicons-warning"></span> ' + message)
            .show();
    }
    
    // 9. FORM SUBMISSION
    $('.gp-folder-form').on('submit', function() {
        // Ensure hidden inputs are updated
        imagesGrid.find('.gp-image-item').each(function() {
            var imgSrc = $(this).find('img').attr('src');
            $(this).find('input[name="existing_images[]"]').val(imgSrc);
        });
    });
    
    // Add CSS animation for spinner
    $('head').append('<style>' +
                    '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }' +
                    '</style>');
    
    // Initialize
    updateImageCount();
});