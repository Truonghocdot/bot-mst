<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('admin_panel_authenticated', false)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $expectedPassword = (string) env('ADMIN_PANEL_PASSWORD', 'change-me');

        if (! hash_equals($expectedPassword, $validated['password'])) {
            return back()
                ->withErrors([
                    'password' => 'Mật khẩu không đúng.',
                ]);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_panel_authenticated', true);

        return redirect()->route('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('admin.panel.authenticated');
        $request->session()->forget('admin_panel_authenticated');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
