<?php

namespace App\Http\Controllers;

use App\Models\Emote;
use App\Services\EmoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EmoteController extends Controller
{
    protected EmoteService $emoteService;

    public function __construct(EmoteService $emoteService)
    {
        $this->emoteService = $emoteService;
    }

    /**
     * Display the emotes page.
     */
    public function index()
    {
        $user = Auth::user();

        return Inertia::render('Emotes/Index', [
            'userEmotes' => $this->emoteService->getUserEmotes($user),
            'globalEmotes' => $this->emoteService->getGlobalEmotes(),
            'favoriteEmotes' => $this->emoteService->getUserFavorites($user),
            'statistics' => $this->emoteService->getStatistics(),
        ]);
    }

    /**
     * Store a new emote.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[a-z0-9_]+$/'],
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,gif,webp', 'max:500'], // 500KB max
            'is_global' => ['boolean'],
        ]);

        // Check if name is available
        if (! $this->emoteService->isNameAvailable($request->name)) {
            return back()->withErrors([
                'name' => 'This emote name is already taken.',
            ]);
        }

        // Check rate limiting (max 5 emotes per day per user)
        $user = Auth::user();
        $recentUploads = $user->uploadedEmotes()
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recentUploads >= 5) {
            return back()->withErrors([
                'image' => 'You can only upload 5 emotes per day.',
            ]);
        }

        try {
            $emote = $this->emoteService->uploadEmote(
                $request->file('image'),
                $request->name,
                $request->boolean('is_global'),
                $user
            );

            return redirect()->route('emotes.index')
                ->with('success', 'Emote uploaded successfully! It will be available after admin approval.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'image' => 'Failed to upload emote. Please try again.',
            ]);
        }
    }

    /**
     * Toggle favorite status for an emote.
     */
    public function toggleFavorite(Emote $emote)
    {
        $user = Auth::user();

        if (! $emote->is_approved) {
            return response()->json(['error' => 'Cannot favorite unapproved emote'], 403);
        }

        $isFavorited = $this->emoteService->toggleFavorite($emote, $user);

        return response()->json([
            'is_favorited' => $isFavorited,
        ]);
    }

    /**
     * Delete user's own emote.
     */
    public function destroy(Emote $emote)
    {
        $user = Auth::user();

        // Check ownership
        if ($emote->uploaded_by_user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Only allow deletion of unapproved emotes
        if ($emote->is_approved) {
            return back()->withErrors([
                'emote' => 'Cannot delete approved emotes.',
            ]);
        }

        $emote->delete();

        return redirect()->route('emotes.index')
            ->with('success', 'Emote deleted successfully.');
    }
}
