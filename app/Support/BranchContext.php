<?php

namespace App\Support;

use App\Models\Branch;

class BranchContext
{
    private ?Branch $branch = null;

    public function set(Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function clear(): void
    {
        $this->branch = null;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function id(): ?int
    {
        return $this->branch?->id;
    }

    public function requireId(): int
    {
        abort_if(! $this->branch, 409, 'Cabang aktif belum dipilih.');

        return $this->branch->id;
    }
}
