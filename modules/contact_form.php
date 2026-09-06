<section class="contact-section">

<div class="container">


<div class="contact-wrapper">


<div class="contact-info">


<h2>
Let's Talk
</h2>

<p>
Have a project, idea or need a software solution?
Our team is ready to help.
</p>


<div class="contact-item">

<i class="bi bi-envelope-fill"></i>

<div>
<h4>Email</h4>
<p>info@scholarug.com</p>
</div>

</div>



<div class="contact-item">

<i class="bi bi-telephone-fill"></i>

<div>
<h4>Phone</h4>
<p>+256 759815047</p>
<p>+256 788643794</p>
</div>

</div>




<div class="contact-item">

<i class="bi bi-geo-alt-fill"></i>

<div>
<h4>Location</h4>
<p>Kampala-Uganda</p>
</div>

</div>



</div>





<div class="contact-card">


<h2>
Send Us A Message
</h2>

<?php if (!empty($contact_message)): ?>
<div class="form-alert form-alert-<?= htmlspecialchars($contact_message_type, ENT_QUOTES, 'UTF-8') ?>">
<?= htmlspecialchars($contact_message, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<form method="post" action="contact.php#contact-form" id="contact-form">

<input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">

<div class="input-group">

<input
type="text"
name="name"
placeholder="Your Name"
value="<?= htmlspecialchars($contact_values['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
required>

</div>



<div class="input-group">

<input
type="email"
name="email"
placeholder="Your Email"
value="<?= htmlspecialchars($contact_values['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
required>

</div>



<div class="input-group">

<input
type="text"
name="subject"
placeholder="Subject"
value="<?= htmlspecialchars($contact_values['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

</div>



<div class="input-group">

<textarea
name="message"
rows="5"
placeholder="Your Message"
required><?= htmlspecialchars($contact_values['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

</div>



<button type="submit">

Send Message
<i class="bi bi-send-fill"></i>

</button>


</form>


</div>



</div>


</div>


</section>