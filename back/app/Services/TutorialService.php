<?php

namespace App\Services;

use App\Contracts\Repositories\TutorialRepositoryInterface;
use App\Models\Tutorial;
use Illuminate\Database\Eloquent\Collection;

class TutorialService
{
    public function __construct(
        private TutorialRepositoryInterface $tutorialRepository,
    ) {}

    public function list(): Collection
    {
        return $this->tutorialRepository->listOrdered();
    }

    public function findByGuid(string $guid): ?Tutorial
    {
        return $this->tutorialRepository->findByGuid($guid);
    }

    public function store(array $data): Tutorial
    {
        return $this->tutorialRepository->create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'source'      => $data['source'],
            'code'        => $data['code'],
            'order'       => $data['order'] ?? 0,
        ]);
    }

    public function update(Tutorial $tutorial, array $data): Tutorial
    {
        /** @var Tutorial */
        return $this->tutorialRepository->update($tutorial, [
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'source'      => $data['source'],
            'code'        => $data['code'],
            'order'       => $data['order'] ?? 0,
        ]);
    }

    public function destroy(Tutorial $tutorial): void
    {
        $this->tutorialRepository->destroy($tutorial);
    }
}
