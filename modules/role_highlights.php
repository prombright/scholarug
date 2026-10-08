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



<div class="role-grid">

<div class="role-card">
<div class="role-icon">
<i class="bi bi-speedometer2"></i>
</div>
<h3>
For School Admins
</h3>
<p>
Enrol students, organize classes and streams, set fees, open
assessments, and see the whole school's performance from one dashboard.
</p>
</div>

<div class="role-card">
<div class="role-icon">
<i class="bi bi-pencil-square"></i>
</div>
<h3>
For Teachers
</h3>
<p>
Enter marks by class or bulk-import them from a spreadsheet, generate
report cards in one click, and see class and subject performance at a
glance.
</p>
</div>

<div class="role-card">
<div class="role-icon">
<i class="bi bi-people-fill"></i>
</div>
<h3>
For Students &amp; Parents
</h3>
<p>
Students and parents each get their own login -- report cards, fee
balances, timetables and direct messaging with teachers, all in one
place.
</p>
</div>

<div class="role-card">
<div class="role-icon">
<i class="bi bi-briefcase-fill"></i>
</div>
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
/* Same 4 roles/same copy as always -- a "spotlight" hover instead of a
   flat grid: hovering one card pulls it forward (lift + slight tilt)
   while the rest ease back and soften, so attention follows the pointer
   instead of all four competing equally for it. Falls back to the plain
   static grid wherever hover doesn't really mean anything (touch), since
   nothing here depends on the effect to be usable. */
.role-flow { padding: 90px 0; }
.role-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 28px; }

.role-card {
    background: #fff;
    border-radius: 20px;
    padding: 40px 28px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(10,61,98,.06);
    transition: transform .45s cubic-bezier(.22,1,.36,1), filter .45s ease, opacity .45s ease, box-shadow .45s ease;
    transform: scale(1) rotate(0deg);
    filter: blur(0);
    opacity: 1;
    cursor: default;
}

/* Only kicks in once a pointer that can actually hover is present --
   @media(hover:hover) keeps touch devices on the plain static grid
   instead of a hover state that could get stuck "on" after a tap. */
@media (hover: hover) {
    .role-grid:hover .role-card { filter: blur(2.5px); opacity: .55; transform: scale(.94); }
    .role-grid:hover .role-card:hover {
        filter: blur(0);
        opacity: 1;
        transform: scale(1.08) rotate(-1.5deg);
        box-shadow: 0 30px 60px rgba(10,61,98,.2);
        z-index: 2;
    }
}

.role-icon { width: 76px; height: 76px; border-radius: 50%; background: var(--light); display: flex; align-items: center; justify-content: center; margin: 0 auto 22px; }
.role-icon i { font-size: 32px; color: var(--secondary); }
.role-card h3 { font-size: 21px; color: var(--primary); margin-bottom: 12px; }
.role-card p { font-size: 15px; line-height: 1.7; color: var(--text); }

@media (max-width: 1024px) {
    .role-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 560px) {
    .role-grid { grid-template-columns: 1fr; }
}
</style>
