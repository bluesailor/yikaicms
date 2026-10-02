<?php
/**
 * 相册灯箱（PhotoSwipe）。page.php 的相册栏目与 album.php 共用。需要：$albumPhotos。
 */
?>
<?php if (!empty($albumPhotos)): ?>
<?php /* PhotoSwipe 灯箱（相册；替代手写 lightbox） */ ?>

<link rel="stylesheet" href="/assets/photoswipe/photoswipe.css">
<script src="/assets/photoswipe/photoswipe.umd.min.js"></script>
<script src="/assets/photoswipe/photoswipe-lightbox.umd.min.js"></script>
<script>
(function () {
    var images = <?php echo json_encode(array_map(function ($p) { return ['src' => $p['image'], 'title' => $p['title']]; }, $albumPhotos), JSON_UNESCAPED_UNICODE); ?>;
    var dims = {};
    images.forEach(function (im) { var pr = new Image(); pr.onload = function () { dims[im.src] = { w: pr.naturalWidth, h: pr.naturalHeight }; }; pr.src = im.src; });
    function openAlbum(idx) {
        if (!window.PhotoSwipeLightbox) return;
        var ds = images.map(function (im) { var d = dims[im.src] || { w: 1600, h: 1600 }; return { src: im.src, width: d.w, height: d.h, alt: im.title }; });
        var lb = new PhotoSwipeLightbox({ dataSource: ds, pswpModule: window.PhotoSwipe, showHideAnimationType: 'zoom', bgOpacity: 0.92 });
        lb.init();
        lb.loadAndOpen(idx || 0);
    }
    document.querySelectorAll('[data-lightbox="album"]').forEach(function (el, idx) {
        el.addEventListener('click', function (e) { e.preventDefault(); openAlbum(idx); });
    });
})();
</script>
<?php endif; ?>
