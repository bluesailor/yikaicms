(function (window) {
    'use strict';

    function applyImageVariant(image, variant) {
        if (!image || !variant || typeof variant.src !== 'string' || variant.src === '') {
            return;
        }

        if (typeof variant.srcset === 'string' && variant.srcset !== '') {
            image.setAttribute('srcset', variant.srcset);
            if (typeof variant.sizes === 'string' && variant.sizes !== '') {
                image.setAttribute('sizes', variant.sizes);
            } else {
                image.removeAttribute('sizes');
            }
        } else {
            image.removeAttribute('srcset');
            image.removeAttribute('sizes');
        }

        ['width', 'height'].forEach(function (attribute) {
            var value = Number(variant[attribute]);
            if (Number.isFinite(value) && value > 0) {
                image.setAttribute(attribute, String(value));
            } else {
                image.removeAttribute(attribute);
            }
        });

        image.setAttribute('src', variant.src);
    }

    window.YikaiProductGallery = Object.freeze({
        applyImageVariant: applyImageVariant
    });
})(window);
