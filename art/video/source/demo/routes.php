<?php

// Append to routes/web.php of the demo app.

// The route from the README:
Route::get('/posts/for-you', fn (Illuminate\Http\Request $request) => App\Models\Post::recommendedFor($request->user()));

// Demo only: sign in as a user so the browser gets a personal feed.
Route::get('/demo/login/{id}', function (int $id) {
    Illuminate\Support\Facades\Auth::loginUsingId($id);

    return redirect('/posts/for-you');
});
