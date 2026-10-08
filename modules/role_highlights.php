<section class="role-flow">

<div class="container">


<div class="section-title">

<h2>
Built For Every Role In Your School
</h2>

<p>
From the admin's office to a parent's phone, everyone gets exactly what they need -- not one generic screen for everybody.
</p>

</div>



<div class="role-row">

<div class="role-panel">
<div class="role-icon">
<i class="bi bi-speedometer2"></i>
</div>
</div>

<div class="role-copy">
<h3>
For School Admins
</h3>
<p>
Enrol students, organize classes and streams, set fees, open
assessments, and see the whole school's performance from one dashboard.
</p>
</div>

</div>



<div class="role-row reverse">

<div class="role-panel">
<div class="role-icon">
<i class="bi bi-pencil-square"></i>
</div>
</div>

<div class="role-copy">
<h3>
For Teachers
</h3>
<p>
Enter marks by class or bulk-import them from a spreadsheet, generate
report cards in one click, and see class and subject performance at a
glance.
</p>
</div>

</div>



<div class="role-row">

<div class="role-panel">
<div class="role-icon">
<i class="bi bi-people-fill"></i>
</div>
</div>

<div class="role-copy">
<h3>
For Students &amp; Parents
</h3>
<p>
Students and parents each get their own login -- report cards, fee
balances, timetables and direct messaging with teachers, all in one
place.
</p>
</div>

</div>



<div class="role-row reverse">

<div class="role-panel">
<div class="role-icon">
<i class="bi bi-briefcase-fill"></i>
</div>
</div>

<div class="role-copy">
<h3>
For HR &amp; Support Staff
</h3>
<p>
Manage staff records, payroll and leave requests, and reach every
parent instantly with Bulk SMS and WhatsApp.
</p>
</div>

</div>



</div>

</section>

<style>
/* Alternating, one-feature-per-row layout -- same 4 roles/same copy as
   before, just given room to breathe instead of being squeezed into
   equal-height grid cards. Own classes (not .service-card) so this
   doesn't touch scholar_features.php's grid on products.php. */
.role-flow { padding: 90px 0; }
.role-row { display: grid; grid-template-columns: 1fr 1.2fr; align-items: center; gap: 56px; margin-bottom: 64px; }
.role-row:last-child { margin-bottom: 0; }
.role-row.reverse { grid-template-columns: 1.2fr 1fr; }
.role-row.reverse .role-panel { order: 2; }
.role-row.reverse .role-copy { order: 1; }

.role-panel { background: var(--light); border-radius: 24px; padding: 60px; display: flex; align-items: center; justify-content: center; }
.role-icon { width: 140px; height: 140px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 20px 45px rgba(10,61,98,.1); }
.role-icon i { font-size: 56px; color: var(--secondary); }

.role-copy h3 { font-size: 30px; color: var(--primary); margin-bottom: 16px; }
.role-copy p { font-size: 17px; line-height: 1.8; color: var(--text); max-width: 480px; }

@media (max-width: 860px) {
    .role-row, .role-row.reverse { grid-template-columns: 1fr; gap: 28px; margin-bottom: 44px; }
    .role-row.reverse .role-panel, .role-row.reverse .role-copy { order: initial; }
    .role-panel { padding: 36px; }
    .role-icon { width: 100px; height: 100px; }
    .role-icon i { font-size: 40px; }
    .role-copy p { max-width: none; }
}
</style>
