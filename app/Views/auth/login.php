<div class="auth-heading"><span class="eyebrow">Secure access</span><h2>Welcome back</h2><p>Sign in to continue to Rankers Mind CRM.</p></div>
<form class="auth-form" method="post" action="<?= url('login') ?>" novalidate><?= csrf_field() ?>
    <label>Email address<div class="input-icon"><i class="fa-regular fa-envelope"></i><input type="email" name="email" value="<?= e(old('email')) ?>" placeholder="you@rankersmind.com" autocomplete="username" required autofocus></div></label>
    <label>Password<div class="input-icon"><i class="fa-solid fa-lock"></i><input id="loginPassword" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button type="button" data-password-toggle="loginPassword" aria-label="Show password"><i class="fa-regular fa-eye"></i></button></div></label>
    <div class="form-row-between"><label class="check-label"><input type="checkbox" name="remember" value="1"><span>Remember me</span></label><a href="<?= url('forgot-password') ?>">Forgot password?</a></div>
    <button class="button button-primary button-block" type="submit">Sign in <i class="fa-solid fa-arrow-right"></i></button>
</form>
