jQuery(document).ready(function($) {
    console.log('Gallery Frontend JS Loaded');
    
    // Initialize lightbox if available
    if (typeof SimpleLightbox !== 'undefined') {
        $('.gp-lightbox-link').simpleLightbox({
            animationSpeed: 300,
            animationSlide: true,
            captions: true,
            captionSelector: 'self',
            captionType: 'data',
            closeText: '×',
            navText: ['‹', '›'],
            showCounter: true,
            docClose: true,
            swipeClose: true
        });
    } else if ($.fn.simpleLightbox) {
        $('.gp-lightbox-link').simpleLightbox({
            animationSpeed: 300,
            captions: true,
            closeText: '×',
            navText: ['‹', '›'],
            showCounter: true
        });
    }
    
    // Lazy loading for images
    if ('IntersectionObserver' in window) {
        var lazyImages = $('.gp-grid-item img, .gp-folder-image img');
        var imageObserver = new IntersectionObserver(function(entries, observer) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var img = entry.target;
                    var src = img.getAttribute('data-src');
                    if (src) {
                        img.src = src;
                        img.removeAttribute('data-src');
                    }
                    imageObserver.unobserve(img);
                }
            });
        });
        
        lazyImages.each(function() {
            var $img = $(this);
            if (!$img.attr('src')) {
                $img.attr('data-src', $img.attr('data-original') || $img.attr('src'));
                $img.removeAttr('src');
                imageObserver.observe(this);
            }
        });
    }
    
    // Add loading animation
    $('img').on('load', function() {
        $(this).closest('.gp-grid-item, .gp-folder-card').addClass('loaded');
    }).each(function() {
        if (this.complete) {
            $(this).trigger('load');
        }
    });
});


