<?php
$errors = validation_errors();
$editing = !empty($record);
$action = $editing ? url($entity.'/'.$record['id']) : url($entity);
$isClientForm = $entity === 'clients';
$isTaskForm = $entity === 'tasks';
$heading = $isClientForm
    ? ($editing ? 'Edit Client & Portal Login' : 'Create Client & Portal Login')
    : ($isTaskForm && !$editing ? 'Create Manual Task' : ($editing ? 'Edit '.$config['singular'] : 'Create '.$config['singular']));
?>
<section class="page-header">
    <div>
        <a class="back-link" href="<?= url($entity) ?>"><i class="fa-solid fa-arrow-left"></i> Back to <?= e(strtolower($config['title'])) ?></a>
        <h1><?= e($heading) ?></h1>
        <p><?php if($isClientForm): ?>Create the company record and its separate, securely hashed client login in one place.<?php elseif($isTaskForm): ?>Write a standalone task manually or link it to a project, then assign one or more staff members.<?php else: ?>Fields marked with an asterisk are required. Validation is performed on the server.<?php endif; ?></p>
    </div>
</section>
<section class="panel form-panel">
    <form class="entity-form" method="post" action="<?= $action ?>" novalidate>
        <?= csrf_field() ?>
        <?php if($isClientForm && $editing): ?><input type="hidden" name="_client_id" value="<?= (int)$record['id'] ?>"><?php endif; ?>
        <div class="form-grid">
            <?php foreach($config['fields'] as $field=>$meta):
                if($isClientForm && $field==='portal_enabled') continue;
                if($entity==='tickets' && $editing && $field==='status') continue;
                $value=old($field,$record[$field]??($_GET[$field]??($meta['default']??'')));
                $required=str_contains($meta['rules']??'','required');
                $type=$meta['type']??'text';
                $class=!empty($meta['wide'])?'wide':'';
            ?>
            <label class="<?= $class ?> <?= isset($errors[$field])?'has-error':'' ?>">
                <span><?= e($meta['label']) ?><?= $required?' *':'' ?></span>
                <?php if($type==='textarea'): ?>
                    <textarea name="<?= e($field) ?>" rows="4" <?= $required?'required':'' ?>><?= e($value) ?></textarea>
                <?php elseif($type==='select'): ?>
                    <select name="<?= e($field) ?>" <?= $required?'required':'' ?>><option value="">Select <?= e(strtolower($meta['label'])) ?></option><?php if(isset($meta['options'])):foreach($meta['options'] as $key=>$label):$optionValue=is_int($key)?$label:$key;?><option value="<?= e($optionValue) ?>" <?= (string)$value===(string)$optionValue?'selected':'' ?>><?= e($label) ?></option><?php endforeach;elseif(isset($relations[$field])):foreach($relations[$field] as $option):$relation=$meta['relation'];?><option value="<?= e($option[$relation['value']]) ?>" <?= (string)$value===(string)$option[$relation['value']]?'selected':'' ?>><?= e($option[$relation['label']]) ?></option><?php endforeach;endif;?></select>
                <?php elseif($type==='multiselect'): ?>
                    <select name="<?= e($field) ?>[]" multiple size="5"><?php foreach($relations[$field]??[] as $option):$relation=$meta['relation'];?><option value="<?= e($option[$relation['value']]) ?>" <?= in_array((string)$option[$relation['value']],array_map('strval',(array)$value),true)?'selected':'' ?>><?= e($option[$relation['label']]) ?></option><?php endforeach;?></select><small>Hold Ctrl to select multiple staff members.</small>
                <?php elseif($type==='checkbox'): ?>
                    <span class="toggle-control"><input type="checkbox" name="<?= e($field) ?>" value="1" <?= $value?'checked':'' ?>><i></i><em>Enabled</em></span>
                <?php else: ?>
                    <input type="<?= e($type) ?>" name="<?= e($field) ?>" value="<?= e($type==='datetime-local'&&$value?date('Y-m-d\TH:i',strtotime($value)):$value) ?>" <?= isset($meta['step'])?'step="'.e($meta['step']).'"':'' ?> <?= isset($meta['min'])?'min="'.e($meta['min']).'"':'' ?> <?= isset($meta['max'])?'max="'.e($meta['max']).'"':'' ?> <?= $required?'required':'' ?>>
                <?php endif; ?>
                <?php if(isset($errors[$field])): ?><small class="field-error"><?= e(implode(' ',$errors[$field])) ?></small><?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>

        <?php if($isClientForm):
            $portalEnabled=(bool)old('portal_enabled',$editing?($record['portal_enabled']??0):1);
            $portalEmail=old('portal_login_email',$portalUser['email']??($record['email']??''));
            $portalStatus=old('portal_account_status',$portalUser['status']??'active');
            $mustChange=(bool)old('portal_must_change_password',$editing?($portalUser['must_change_password']??0):1);
        ?>
        <section class="client-access-card" id="clientPortalAccess">
            <div class="client-access-head">
                <div><span class="list-icon"><i class="fa-solid fa-user-lock"></i></span><div><h2>Separate client login</h2><p>This login can access only the projects, invoices, files and tickets linked to this client record.</p></div></div>
                <label class="toggle-control portal-toggle"><input type="checkbox" name="portal_enabled" value="1" id="portalEnabled" <?= $portalEnabled?'checked':'' ?>><i></i><em>Portal enabled</em></label>
            </div>
            <div class="form-grid portal-credential-fields" id="portalCredentialFields">
                <label class="<?= isset($errors['portal_login_email'])?'has-error':'' ?>"><span>Client login ID (email) *</span><input type="email" name="portal_login_email" id="portalLoginEmail" value="<?= e($portalEmail) ?>" autocomplete="off"><small>This is the ID the client enters on the CRM login screen.</small><?php if(isset($errors['portal_login_email'])):?><small class="field-error"><?= e(implode(' ',$errors['portal_login_email'])) ?></small><?php endif;?></label>
                <label><span>Portal account status</span><select name="portal_account_status"><?php foreach(['active'=>'Active','inactive'=>'Inactive','locked'=>'Locked'] as $value=>$label):?><option value="<?= $value ?>" <?= $portalStatus===$value?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></label>
                <label class="<?= isset($errors['portal_password'])?'has-error':'' ?>"><span><?= $editing&&$portalUser?'New temporary password':'Temporary password *' ?></span><input type="password" name="portal_password" id="portalPassword" minlength="10" autocomplete="new-password" placeholder="<?= $editing&&$portalUser?'Leave blank to keep current password':'Minimum 10 characters' ?>"><?php if(isset($errors['portal_password'])):?><small class="field-error"><?= e(implode(' ',$errors['portal_password'])) ?></small><?php endif;?></label>
                <label class="<?= isset($errors['portal_password_confirmation'])?'has-error':'' ?>"><span>Confirm temporary password<?= $editing&&$portalUser?'':' *' ?></span><input type="password" name="portal_password_confirmation" id="portalPasswordConfirmation" minlength="10" autocomplete="new-password"><?php if(isset($errors['portal_password_confirmation'])):?><small class="field-error"><?= e(implode(' ',$errors['portal_password_confirmation'])) ?></small><?php endif;?></label>
                <label class="check-label wide"><input type="checkbox" name="portal_must_change_password" value="1" <?= $mustChange?'checked':'' ?>> Require the client to change this temporary password after signing in</label>
                <?php if($editing&&$portalUser): ?><div class="inline-alert info wide"><i class="fa-solid fa-shield-halved"></i><span>Existing login: <strong><?= e($portalUser['email']) ?></strong>. Last sign-in: <?= $portalUser['last_login_at']?e(date('d M Y, g:i A',strtotime($portalUser['last_login_at']))):'Never' ?>.</span></div><?php endif; ?>
            </div>
        </section>
        <script>
        document.addEventListener('DOMContentLoaded',()=>{
            const enabled=document.getElementById('portalEnabled'),fields=document.getElementById('portalCredentialFields'),login=document.getElementById('portalLoginEmail'),clientEmail=document.querySelector('[name="email"]'),password=document.getElementById('portalPassword'),confirmation=document.getElementById('portalPasswordConfirmation');
            const sync=()=>{fields.classList.toggle('disabled',!enabled.checked);fields.querySelectorAll('input,select').forEach(input=>{if(input!==enabled)input.disabled=!enabled.checked});if(enabled.checked&&!login.value&&clientEmail?.value)login.value=clientEmail.value;password.required=enabled.checked&&<?= $editing&&$portalUser?'false':'true' ?>;confirmation.required=password.required};
            enabled.addEventListener('change',sync);clientEmail?.addEventListener('blur',()=>{if(enabled.checked&&!login.value)login.value=clientEmail.value});password.addEventListener('input',()=>{confirmation.required=password.value!==''});sync();
        });
        </script>
        <?php endif; ?>

        <div class="form-actions">
            <a class="button button-secondary" href="<?= url($entity) ?>">Cancel</a>
            <button class="button button-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $editing?'Save changes':($isClientForm?'Create client account':($isTaskForm?'Create manual task':'Create '.$config['singular'])) ?></button>
        </div>
    </form>
</section>
<?php unset($_SESSION['_old']); ?>
