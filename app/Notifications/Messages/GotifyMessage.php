<?php

namespace App\Notifications\Messages;

class GotifyMessage
{
    public string $title = '';
    public string $content = '';
    public string $url = '';
    public int $priority = 5;

    public static function create(string $content = ''): self
    {
        return new static($content);
    }

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function content(string $content = ''): self
    {
        $this->content = $content;

        return $this;
    }

    public function url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }
}
