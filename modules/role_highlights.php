<section class="services role-services">

<div class="container">


<div class="section-title">

<h2>
Built For Every Role In Your School
</h2>

<p>
From the admin's office to a parent's phone, everyone gets exactly what they need -- not one generic screen for everybody.
</p>

</div>



<div class="service-grid">



<div class="service-card">


<div class="service-icon">

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




<div class="service-card">


<div class="service-icon">

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




<div class="service-card">


<div class="service-icon">

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




<div class="service-card">


<div class="service-icon">

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
/* Back to the original .service-card grid (same markup/classes
   scholar_features.php uses on products.php) -- this file now only adds
   a scoped ".role-services" wrapper class so the effects below target
   just these 4 cards, never touching the shared .service-card rules or
   the other page's grid.

   Two layered effects:
   1. An automatic "taking turns" spotlight -- each card gets a brief
      highlighted moment in a continuous loop, staggered so only one is
      ever "active" at a time (4 cards x 2s = 8s full cycle).
   2. Hovering the grid pauses the auto-rotation and hands control to
      the pointer -- the hovered card snaps to the highlighted look,
      its siblings ease back and soften, same spotlight language as the
      automatic version so the two feel like one continuous idea rather
      than two different effects competing. */
.role-services .service-card {
    animation: role-turn 8s infinite;
    transform-origin: center;
}
.role-services .service-card:nth-child(1) { animation-delay: 0s; }
.role-services .service-card:nth-child(2) { animation-delay: 2s; }
.role-services .service-card:nth-child(3) { animation-delay: 4s; }
.role-services .service-card:nth-child(4) { animation-delay: 6s; }

@keyframes role-turn {
    0%, 8% {
        transform: scale(1.06) rotate(-1.5deg);
        filter: blur(0);
        opacity: 1;
        box-shadow: 0 28px 55px rgba(10,61,98,.18);
    }
    22%, 100% {
        transform: scale(1) rotate(0deg);
        filter: blur(.5px);
        opacity: .82;
        box-shadow: 0 10px 30px rgba(10,61,98,.06);
    }
}

@media (hover: hover) {
    .role-services .service-grid:hover .service-card { animation-play-state: paused; }
    .role-services .service-grid:hover .service-card {
        transform: scale(.95) rotate(0deg) !important;
        filter: blur(2px) !important;
        opacity: .6 !important;
        box-shadow: 0 10px 30px rgba(10,61,98,.06) !important;
    }
    .role-services .service-grid:hover .service-card:hover {
        transform: scale(1.08) rotate(-1.5deg) !important;
        filter: blur(0) !important;
        opacity: 1 !important;
        box-shadow: 0 30px 60px rgba(10,61,98,.2) !important;
        z-index: 2;
    }
}

@media (prefers-reduced-motion: reduce) {
    .role-services .service-card { animation: none; }
}
</style>
