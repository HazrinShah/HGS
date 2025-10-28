<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$hikerStatus = strtolower($_SESSION['hiker_status'] ?? '');
$guiderStatus = strtolower($_SESSION['guider_status'] ?? '');
$isHikerSuspended = ($hikerStatus === 'suspended');
$isGuiderSuspended = ($guiderStatus === 'suspended');

// Determine current role in this session context
$isGuiderLoggedIn = !empty($_SESSION['guiderID']);
$isHikerLoggedIn  = !empty($_SESSION['hikerID']);

// Only show banner for the actively logged-in role
$shouldShow = false;
$activeRole = null; // 'hiker' | 'guider'
if ($isGuiderLoggedIn) {
    $activeRole = 'guider';
    $shouldShow = $isGuiderSuspended;
} elseif ($isHikerLoggedIn) {
    $activeRole = 'hiker';
    $shouldShow = $isHikerSuspended;
}

if (!$shouldShow) {
    return; // Nothing to render for this role
}

// Determine current script for page-specific behavior
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Banner styles
$bannerStyle = "position:sticky;top:0;z-index:1001;";
// Match Hiker HBooking banner design exactly (soft red warning)
$innerStyle = "background:linear-gradient(135deg,#fee2e2,#fecaca);border:1px solid #ef4444;color:#7f1d1d;padding:12px 16px;text-align:center;font-weight:700;display:flex;justify-content:center;align-items:center;gap:10px;";
$closeStyle = "margin-left:12px;background:transparent;border:none;color:#7f1d1d;cursor:pointer;font-weight:800;font-size:16px;";

// Message (role-specific, exact sentences)
$message = '';
if ($activeRole === 'hiker') {
    $message = 'Your account is currently suspended. You cannot make any bookings until it is unsuspended.';
} elseif ($activeRole === 'guider') {
    $message = 'Your account is currently suspended. Hikers will not be able to find or book you until it is lifted.';
}

?>
<div id="suspension-banner" style="<?php echo $bannerStyle; ?>">
  <div style="<?php echo $innerStyle; ?>">
    <span>⚠️</span>
    <span><?php echo htmlspecialchars($message); ?></span>
    <button id="suspensionBannerClose" style="<?php echo $closeStyle; ?>" aria-label="Dismiss">×</button>
  </div>
</div>
<script>
(function(){
  var closeBtn = document.getElementById('suspensionBannerClose');
  var banner = document.getElementById('suspension-banner');
  if (closeBtn && banner) {
    closeBtn.addEventListener('click', function(){ banner.parentNode && banner.parentNode.removeChild(banner); });
  }

  // If hiker is suspended and on a booking page, disable booking interactions
  var isHikerSuspended = <?php echo ($activeRole === 'hiker' && $isHikerSuspended) ? 'true' : 'false'; ?>;
  var scriptName = <?php echo json_encode($currentScript); ?>;
  var isHikerBookingPage = isHikerSuspended && /HBooking/i.test(scriptName);
  if (isHikerBookingPage) {
    document.addEventListener('DOMContentLoaded', function(){
      var container = document.querySelector('.main-content') || document.body;
      if(!container) return;
      container.querySelectorAll('button, a.btn, input[type="submit"], [data-bs-target="#dateModal"]').forEach(function(el){
        el.classList.add('disabled');
        el.setAttribute('aria-disabled','true');
        if (el.tagName === 'A') {
          el.addEventListener('click', function(e){ e.preventDefault(); });
        } else {
          el.disabled = true;
        }
        if (!el.title) {
          el.title = 'Booking actions are disabled while your account is suspended';
        }
      });
      // Prevent form submissions in booking pages
      document.querySelectorAll('form').forEach(function(f){ f.addEventListener('submit', function(e){ e.preventDefault(); }); });
    });
  }
})();
</script>
