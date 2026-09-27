    </main>
  </div>
</div>
<script>
window.addEventListener('click', function(e){
  if (window.innerWidth <= 900 && document.body.classList.contains('sidebar-open') && !e.target.closest('.sidebar') && !e.target.closest('.menu-toggle')) {
    document.body.classList.remove('sidebar-open');
  }
});
</script>
</body>
</html>
