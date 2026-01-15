<?php

class NotePage extends Page
{
    public function isReadable(): bool
    {
        // Admins can read everything
        if ($this->kirby()->user() && $this->kirby()->user()->isAdmin()) {
            return true;
        }

        // Notes are readable by their author
        if ($this->author()->toUser()?->id() === $this->kirby()->user()?->id()) {
            return true;
        }

        // Authors cannot read other people's notes in the panel
        if ($this->kirby()->user() && $this->kirby()->user()->role()->name() === 'author') {
            return false;
        }

        return parent::isReadable();
    }

    public function isDeletable(): bool
    {
        // Admins can delete everything
        if ($this->kirby()->user() && $this->kirby()->user()->isAdmin()) {
            return true;
        }

        // Notes are deletable by their author
        if ($this->author()->toUser()?->id() === $this->kirby()->user()?->id()) {
            return true;
        }

        // Authors cannot delete other people's notes
        if ($this->kirby()->user() && $this->kirby()->user()->role()->name() === 'author') {
            return false;
        }

        return parent::isDeletable();
    }
}
