// GALLERY LIGHTBOX - GUARANTEED TO WORK
// Save this as /assets/js/lightbox-only.js

(function() {
    'use strict';
    
    // Wait for page to load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        console.log('Gallery Lightbox Initializing...');
        
        // Wait a bit for images to load
        setTimeout(function() {
            setupLightbox();
        }, 1500);
    }
    
    function setupLightbox() {
        // Find ALL image links in the gallery
        var galleryContainers = document.querySelectorAll('.gp-all-folders, .gp-single-folder');
        
        galleryContainers.forEach(function(container) {
            var imageLinks = container.querySelectorAll('a[href$=".jpg"], a[href$=".jpeg"], a[href$=".png"], a[href$=".gif"], a[href$=".webp"]');
            
            console.log('Found ' + imageLinks.length + ' image links in container');
            
            imageLinks.forEach(function(link) {
                // Skip if already processed
                if (link.classList.contains('gp-lightbox-processed')) {
                    return;
                }
                
                // Mark as processed
                link.classList.add('gp-lightbox-processed');
                link.classList.add('gp-lightbox-link');
                link.style.cursor = 'zoom-in';
                
                // Remove any existing click events
                var newLink = link.cloneNode(true);
                link.parentNode.replaceChild(newLink, link);
                
                // Add click event
                newLink.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    openLightbox(this.href, this.querySelector('img')?.alt || 'Gallery Image');
                });
            });
        });
    }
    
    function openLightbox(imageUrl, imageTitle) {
        console.log('Opening lightbox for:', imageUrl);
        
        // Close existing lightbox
        closeLightbox();
        
        // Create lightbox
        var lightbox = document.createElement('div');
        lightbox.id = 'gp-working-lightbox';
        lightbox.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.97);
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        `;
        
        lightbox.innerHTML = `
            <div style="position: relative; text-align: center;">
                <img src="${imageUrl}" 
                     alt="${imageTitle}"
                     style="
                        max-width: 95vw;
                        max-height: 90vh;
                        border-radius: 8px;
                        box-shadow: 0 10px 40px rgba(0,0,0,0.5);
                        display: block;
                     "
                     onload="this.parentElement.parentElement.style.opacity='1'">
                
                <button id="gp-close-lightbox" 
                        style="
                            position: absolute;
                            top: -50px;
                            right: 0;
                            background: rgba(255,255,255,0.2);
                            border: none;
                            color: white;
                            font-size: 30px;
                            width: 40px;
                            height: 40px;
                            border-radius: 50%;
                            cursor: pointer;
                            line-height: 1;
                        ">×</button>
                
                <div style="color: white; margin-top: 20px; font-size: 18px; padding: 0 20px;">
                    ${imageTitle}
                </div>
            </div>
        `;
        
        document.body.appendChild(lightbox);
        
        // Setup events
        setTimeout(function() {
            lightbox.style.opacity = '1';
            
            // Close button
            document.getElementById('gp-close-lightbox').addEventListener('click', closeLightbox);
            
            // Close on overlay click
            lightbox.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeLightbox();
                }
            });
            
            // Close with ESC
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape') {
                    closeLightbox();
                    document.removeEventListener('keydown', escHandler);
                }
            });
        }, 10);
    }
    
    function closeLightbox() {
        var lightbox = document.getElementById('gp-working-lightbox');
        if (lightbox) {
            lightbox.style.opacity = '0';
            setTimeout(function() {
                if (lightbox.parentNode) {
                    lightbox.parentNode.removeChild(lightbox);
                }
            }, 300);
        }
    }
    
    // Export to global scope for debugging
    window.galleryLightbox = {
        init: init,
        open: openLightbox,
        close: closeLightbox
    };
    
})();