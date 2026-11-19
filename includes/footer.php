</div><!-- end .app-content -->
</div><!-- end .app-layout -->

<footer class="footer mt-auto py-3 bg-white border-top">
    <div class="container-fluid text-center">
        <span class="text-muted" style="font-size: 0.8rem;">© 2026 LearnAlytics. All rights reserved.</span>
    </div>
</footer>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Custom JS -->
<script src="<?php echo $baseUrl; ?>/assets/js/main.js"></script>

<!-- Sidebar Toggle Script -->
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.toggle('sidebar-open');
    overlay.classList.toggle('active');
    document.body.style.overflow = sidebar.classList.contains('sidebar-open') ? 'hidden' : '';
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !overlay) return;
    sidebar.classList.remove('sidebar-open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
}

// Close sidebar on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSidebar();
});
</script>

<?php
$viewerModalPath = __DIR__ . '/file_viewer_modal.php';
if (file_exists($viewerModalPath)) {
    include_once $viewerModalPath;
}
?>
</body>
</html>
