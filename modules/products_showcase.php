<section class="products">


<div class="container">


<div class="product-showcase">




<!-- SCHOLAR -->

<div class="platform-card">


<div class="platform-image platform-image-mock">

<!-- The real product, not a stock photo -- assets/images/products/scholar.png
     turned out to be a screenshot of an unrelated product (a clinic/patient
     system called "iClinic"), left over from a template and never swapped
     for anything real. Reuses the exact same dashboard-mockup styling as
     the homepage hero (modules/hero.php's .mock-window) instead of a photo
     that would misrepresent what Scholar actually looks like. -->
<div class="mock-window">
    <div class="mock-titlebar"><span></span><span></span><span></span></div>
    <div class="mock-body">
        <div class="mock-sidebar">
            <div class="mock-sidebar-dot"></div>
            <i class="bi bi-grid-1x2"></i>
            <i class="bi bi-people"></i>
            <i class="bi bi-journal-bookmark"></i>
            <i class="bi bi-cash-coin"></i>
            <i class="bi bi-gear"></i>
        </div>
        <div class="mock-main">
            <div class="mock-stats">
                <div class="mock-stat"><span class="mock-stat-n">312</span><span class="mock-stat-l">Students</span></div>
                <div class="mock-stat"><span class="mock-stat-n">28</span><span class="mock-stat-l">Staff</span></div>
                <div class="mock-stat mock-stat-accent"><span class="mock-stat-n">A</span><span class="mock-stat-l">Exceptional</span></div>
            </div>
            <div class="mock-bars">
                <span style="height:38%"></span>
                <span style="height:64%"></span>
                <span style="height:52%"></span>
                <span style="height:80%"></span>
                <span style="height:46%"></span>
                <span style="height:70%"></span>
            </div>
        </div>
    </div>
</div>

</div>



<div class="platform-content">


<h2>
Scholar
</h2>


<span>
School Management System
</span>



<p>

A complete platform helping schools manage
students, fees, academics, communication
and administration.

</p>



<div class="features">


<span>
Students
</span>


<span>
Fees
</span>


<span>
Results
</span>


<span>
Reports
</span>


</div>




<a href="scholar/index.php"
class="btn btn-primary">

Open Scholar

</a>


</div>


</div>


</div>


</div>


</section>

<style>
/* Same dashboard-mockup look as the homepage hero (modules/hero.php),
   just fitted to .platform-image's 350px-tall frame instead of standing
   free -- see the markup comment above for why this replaced a photo. */
.platform-image-mock { display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #0A3D62, #071E26); padding: 24px; }
.platform-image-mock .mock-window { width: 100%; max-width: 420px; background: #131b28; border: 1px solid #2a3a52; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 45px rgba(0,0,0,.35); }
.platform-image-mock .mock-titlebar { display: flex; gap: 6px; padding: 10px 14px; background: #182233; }
.platform-image-mock .mock-titlebar span { width: 8px; height: 8px; border-radius: 50%; background: #2a3a52; }
.platform-image-mock .mock-body { display: flex; }
.platform-image-mock .mock-sidebar { width: 46px; background: #0e1620; display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 16px 0; }
.platform-image-mock .mock-sidebar-dot { width: 18px; height: 18px; border-radius: 6px; background: var(--secondary); margin-bottom: 2px; }
.platform-image-mock .mock-sidebar i { color: #64748b; font-size: 14px; }
.platform-image-mock .mock-sidebar i:first-of-type { color: var(--secondary); }
.platform-image-mock .mock-main { flex: 1; padding: 18px 16px; }
.platform-image-mock .mock-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 16px; }
.platform-image-mock .mock-stat { background: #182233; border: 1px solid #2a3a52; border-radius: 9px; padding: 10px 8px; display: flex; flex-direction: column; gap: 2px; }
.platform-image-mock .mock-stat-n { font-size: 16px; font-weight: 800; color: #e2e8f0; }
.platform-image-mock .mock-stat-l { font-size: 8.5px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.platform-image-mock .mock-stat-accent { background: rgba(0,168,168,.12); border-color: rgba(0,168,168,.35); }
.platform-image-mock .mock-stat-accent .mock-stat-n { color: var(--secondary); }
.platform-image-mock .mock-bars { display: flex; align-items: flex-end; gap: 7px; height: 70px; background: #182233; border: 1px solid #2a3a52; border-radius: 9px; padding: 12px 14px; }
.platform-image-mock .mock-bars span { flex: 1; background: var(--secondary); border-radius: 4px 4px 0 0; }
</style>
