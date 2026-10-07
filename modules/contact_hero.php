<section class="contact-hero">

    <div class="container">


        <h1>
            Contact ScholarUg
        </h1>


        <p>
            Let's discuss how technology can transform your organization.
        </p>


    </div>

</section>

<style>
/* Was ".contact_hero" (underscore) -- didn't match any rule in
   style.css at all (the convention everywhere else is a hyphen, see
   .about-hero/.products-hero), so this page silently never got the
   same navy hero band every other page has. Same treatment as
   .about-hero, scoped here instead of style.css since nothing else
   needs it. */
.contact-hero {
    padding: 120px 0;
    background: var(--primary);
    color: white;
    text-align: center;
}
.contact-hero h1 {
    font-size: 55px;
    font-weight: 800;
    margin-bottom: 20px;
}
.contact-hero p {
    font-size: 18px;
    max-width: 700px;
    margin: auto;
    line-height: 1.8;
}
@media (max-width: 768px) {
    .contact-hero { padding: 80px 0; }
    .contact-hero h1 { font-size: 38px; }
}
</style>