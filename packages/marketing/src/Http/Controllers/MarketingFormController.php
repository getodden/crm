<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Odden\Marketing\Actions\ProcessFormSubmissionAction;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Support\ContactToken;
use Odden\Marketing\Support\VisitorToken;

class MarketingFormController extends Controller
{
    /**
     * Display a hosted lead capture form.
     */
    public function show(Request $request, string $slug): Response
    {
        /** @var MarketingForm $form */
        $form = MarketingForm::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        // Only a signed link identifies a returning contact; a bare id or email would
        // let anyone read another contact's name and profile gaps.
        $contact = ContactToken::resolve($request->query('contact'), ContactToken::forForm($form->id));

        $fields = $form->resolveFieldsForContact($contact);

        return response()->view('odden-marketing::forms.show', [
            'form' => $form,
            'contact' => $contact,
            'contactToken' => $contact !== null ? ContactToken::make($contact, ContactToken::forForm($form->id)) : null,
            'fields' => $fields,
        ]);
    }

    /**
     * Handle incoming form submission.
     */
    public function submit(Request $request, string $slug, ProcessFormSubmissionAction $action): JsonResponse|RedirectResponse|Response
    {
        /** @var MarketingForm $form */
        $form = MarketingForm::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $verifiedContact = ContactToken::resolve($request->input('contact'), ContactToken::forForm($form->id));

        // Validate against the fields the visitor was shown. Only a signed link identifies a
        // returning contact (see show()), so an email typed into the form must not switch the
        // rules to the progressive fields the visitor never saw.
        $request->validate($form->validationRulesFor($form->resolveFieldsForContact($verifiedContact)));

        $submission = $action->execute(
            form: $form,
            data: $request->except(['contact']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            contact: $verifiedContact,
        );

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => $form->success_message ?? 'Thank you for your submission!',
                'redirect_url' => $form->redirect_url,
                'submission_id' => $submission->id,
            ]);
        }

        if (! empty($form->redirect_url)) {
            return redirect()->away($form->redirect_url);
        }

        return response()->view('odden-marketing::forms.success', [
            'form' => $form,
        ]);
    }

    /**
     * Provide JSON schema for headless marketing frontends (Next.js, Remix, Webflow).
     */
    public function schema(Request $request, string $slug): JsonResponse
    {
        /** @var MarketingForm $form */
        $form = MarketingForm::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        // Only a signed link identifies a returning contact; a bare id or email would
        // let anyone read another contact's name and profile gaps.
        $contact = ContactToken::resolve($request->query('contact'), ContactToken::forForm($form->id));

        $fields = $form->resolveFieldsForContact($contact);

        return response()->json([
            'id' => $form->id,
            'title' => $form->title,
            'slug' => $form->slug,
            'description' => $form->description,
            'submit_button_text' => $form->submit_button_text ?: 'Submit',
            'action_url' => route('odden.marketing.forms.api-submit', $form->slug),
            'fields' => $fields,
            'progressive_profiling' => (bool) $form->progressive_profiling_enabled,
        ]);
    }

    /**
     * Serve lightweight embeddable form JavaScript widget.
     */
    public function embedScript(Request $request, ?string $slug = null): Response
    {
        // Absolute URL template so the script works when embedded on other domains and honors route prefixes.
        $schemaUrlTemplate = json_encode(route('odden.marketing.forms.schema', '__SLUG__'), JSON_UNESCAPED_SLASHES);
        $targetSlug = $slug !== null ? json_encode($slug) : 'null';
        // Same visitor id helper as odden.js, so embedded submissions stitch to tracked sessions.
        $visitorIdJs = VisitorToken::javascript();

        $js = <<<JAVASCRIPT
(function() {
    var ODDEN_SCHEMA_URL = {$schemaUrlTemplate};
    var TARGET_SLUG = {$targetSlug};

{$visitorIdJs}
    function renderForm(container, formSchema) {
        var form = document.createElement('form');
        form.className = 'odden-embedded-form';
        form.style.fontFamily = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        form.style.maxWidth = '480px';
        form.style.margin = '0 auto';
        form.style.padding = '24px';
        form.style.backgroundColor = '#ffffff';
        form.style.borderRadius = '12px';
        form.style.border = '1px solid #e2e8f0';
        form.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';

        if (formSchema.title) {
            var h = document.createElement('h3');
            h.innerText = formSchema.title;
            h.style.margin = '0 0 8px 0';
            h.style.fontSize = '20px';
            h.style.color = '#0f172a';
            form.appendChild(h);
        }

        if (formSchema.description) {
            var p = document.createElement('p');
            p.innerText = formSchema.description;
            p.style.margin = '0 0 20px 0';
            p.style.fontSize = '14px';
            p.style.color = '#64748b';
            form.appendChild(p);
        }

        var errorBox = document.createElement('div');
        errorBox.style.display = 'none';
        errorBox.style.padding = '12px';
        errorBox.style.marginBottom = '16px';
        errorBox.style.backgroundColor = '#fef2f2';
        errorBox.style.color = '#991b1b';
        errorBox.style.borderRadius = '8px';
        errorBox.style.fontSize = '13px';
        form.appendChild(errorBox);

        formSchema.fields.forEach(function(field) {
            var wrapper = document.createElement('div');
            wrapper.style.marginBottom = '16px';

            var label = document.createElement('label');
            label.innerText = field.label || field.name;
            label.style.display = 'block';
            label.style.marginBottom = '6px';
            label.style.fontSize = '13px';
            label.style.fontWeight = '600';
            label.style.color = '#334155';
            if (field.required) {
                label.innerHTML += ' <span style="color:#ef4444">*</span>';
            }
            wrapper.appendChild(label);

            var input;
            if (field.type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 3;
            } else if (field.type === 'select' && Array.isArray(field.options)) {
                input = document.createElement('select');
                var defOpt = document.createElement('option');
                defOpt.value = '';
                defOpt.innerText = '-- Select --';
                input.appendChild(defOpt);
                field.options.forEach(function(opt) {
                    var o = document.createElement('option');
                    o.value = opt.value || opt;
                    o.innerText = opt.label || opt;
                    input.appendChild(o);
                });
            } else {
                input = document.createElement('input');
                input.type = field.type || 'text';
            }

            input.name = field.name;
            input.placeholder = field.placeholder || '';
            if (field.required) input.required = true;

            input.style.width = '100%';
            input.style.boxSizing = 'border-box';
            input.style.padding = '10px 14px';
            input.style.fontSize = '14px';
            input.style.border = '1px solid #cbd5e1';
            input.style.borderRadius = '8px';
            input.style.outline = 'none';

            wrapper.appendChild(input);
            form.appendChild(wrapper);
        });

        var btn = document.createElement('button');
        btn.type = 'submit';
        btn.innerText = formSchema.submit_button_text || 'Submit';
        btn.style.width = '100%';
        btn.style.padding = '12px 20px';
        btn.style.backgroundColor = '#2563eb';
        btn.style.color = '#ffffff';
        btn.style.border = 'none';
        btn.style.borderRadius = '8px';
        btn.style.fontWeight = '600';
        btn.style.fontSize = '14px';
        btn.style.cursor = 'pointer';
        form.appendChild(btn);

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            btn.disabled = true;
            btn.innerText = 'Submitting...';
            errorBox.style.display = 'none';

            var formData = new FormData(form);
            var payload = {};
            formData.forEach(function(value, key) {
                payload[key] = value;
            });

            payload['visitor_token'] = oddenVisitorId();

            fetch(formSchema.action_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(function(res) {
                return res.json().then(function(json) {
                    return { ok: res.ok, data: json };
                });
            })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error(res.data.message || 'Submission failed. Please check your inputs.');
                }

                if (res.data.redirect_url) {
                    window.location.href = res.data.redirect_url;
                } else {
                    form.innerHTML = '<div style="padding:24px;text-align:center;color:#166534;background:#f0fdf4;border-radius:8px;font-weight:600;">' +
                        (res.data.message || 'Thank you for your submission!') +
                        '</div>';
                }
            })
            .catch(function(err) {
                errorBox.innerText = err.message;
                errorBox.style.display = 'block';
                btn.disabled = false;
                btn.innerText = formSchema.submit_button_text || 'Submit';
            });
        });

        var displayMode = container.getAttribute('data-odden-display') || 'inline';
        var trigger = container.getAttribute('data-odden-trigger') || 'immediate';
        var dismissedKey = '_odden_dismissed_' + (formSchema.slug || 'form');

        if (displayMode !== 'inline' && sessionStorage.getItem(dismissedKey)) {
            return;
        }

        var wrapper;
        var shown = false;

        function closePopup() {
            sessionStorage.setItem(dismissedKey, '1');
            if (wrapper && wrapper.parentNode) {
                if (displayMode === 'modal') {
                    wrapper.style.opacity = '0';
                    setTimeout(function() { wrapper.remove(); }, 300);
                } else if (displayMode === 'slide-in') {
                    wrapper.style.transform = 'translateY(120%)';
                    setTimeout(function() { wrapper.remove(); }, 400);
                } else {
                    wrapper.remove();
                }
            }
        }

        if (displayMode === 'modal') {
            wrapper = document.createElement('div');
            wrapper.className = 'odden-modal-overlay';
            wrapper.style.position = 'fixed';
            wrapper.style.inset = '0';
            wrapper.style.backgroundColor = 'rgba(15, 23, 42, 0.65)';
            wrapper.style.backdropFilter = 'blur(4px)';
            wrapper.style.zIndex = '999999';
            wrapper.style.display = 'flex';
            wrapper.style.alignItems = 'center';
            wrapper.style.justifyContent = 'center';
            wrapper.style.opacity = '0';
            wrapper.style.transition = 'opacity 0.3s ease';

            var card = document.createElement('div');
            card.style.position = 'relative';
            card.style.maxWidth = '520px';
            card.style.width = '90%';

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.innerHTML = '&times;';
            closeBtn.style.position = 'absolute';
            closeBtn.style.top = '12px';
            closeBtn.style.right = '16px';
            closeBtn.style.background = 'none';
            closeBtn.style.border = 'none';
            closeBtn.style.fontSize = '24px';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.color = '#94a3b8';
            closeBtn.onclick = closePopup;

            wrapper.onclick = function(e) { if (e.target === wrapper) closePopup(); };

            form.style.boxShadow = '0 25px 50px -12px rgba(0, 0, 0, 0.25)';
            card.appendChild(form);
            card.appendChild(closeBtn);
            wrapper.appendChild(card);
        } else if (displayMode === 'slide-in') {
            wrapper = document.createElement('div');
            wrapper.className = 'odden-slide-in';
            wrapper.style.position = 'fixed';
            wrapper.style.bottom = '24px';
            wrapper.style.right = '24px';
            wrapper.style.width = '380px';
            wrapper.style.maxWidth = 'calc(100vw - 48px)';
            wrapper.style.zIndex = '999999';
            wrapper.style.transform = 'translateY(120%)';
            wrapper.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)';

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.innerHTML = '&times;';
            closeBtn.style.position = 'absolute';
            closeBtn.style.top = '8px';
            closeBtn.style.right = '14px';
            closeBtn.style.background = 'none';
            closeBtn.style.border = 'none';
            closeBtn.style.fontSize = '22px';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.color = '#94a3b8';
            closeBtn.onclick = closePopup;

            form.style.boxShadow = '0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1)';
            wrapper.appendChild(form);
            wrapper.appendChild(closeBtn);
        }

        function showPopup() {
            if (shown) return;
            shown = true;
            document.body.appendChild(wrapper);
            setTimeout(function() {
                if (displayMode === 'modal') wrapper.style.opacity = '1';
                if (displayMode === 'slide-in') wrapper.style.transform = 'translateY(0)';
            }, 10);
        }

        if (displayMode === 'inline') {
            container.innerHTML = '';
            container.appendChild(form);
        } else {
            if (trigger === 'immediate') {
                showPopup();
            } else if (trigger === 'exit-intent') {
                document.addEventListener('mouseleave', function(e) {
                    if (e.clientY <= 10) showPopup();
                });
            } else if (trigger === 'scroll-50') {
                window.addEventListener('scroll', function() {
                    var total = document.documentElement.scrollHeight || document.body.scrollHeight;
                    var current = window.scrollY + window.innerHeight;
                    if ((current / total) >= 0.5) showPopup();
                });
            } else if (trigger.indexOf('delay-') === 0) {
                var secs = parseInt(trigger.replace('delay-', ''), 10) || 5;
                setTimeout(showPopup, secs * 1000);
            } else {
                showPopup();
            }
        }
    }

    function init() {
        var containers = [];
        if (TARGET_SLUG) {
            var el = document.querySelector('[data-odden-form="' + TARGET_SLUG + '"]') || document.getElementById('odden-form-' + TARGET_SLUG);
            if (el) containers.push({ el: el, slug: TARGET_SLUG });
        } else {
            document.querySelectorAll('[data-odden-form]').forEach(function(el) {
                var s = el.getAttribute('data-odden-form');
                if (s) containers.push({ el: el, slug: s });
            });
        }

        containers.forEach(function(item) {
            fetch(ODDEN_SCHEMA_URL.replace('__SLUG__', encodeURIComponent(item.slug)))
                .then(function(res) { return res.json(); })
                .then(function(schema) {
                    renderForm(item.el, schema);
                })
                .catch(function(err) {
                    console.error('[Odden Forms] Failed to load schema for', item.slug, err);
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JAVASCRIPT;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
