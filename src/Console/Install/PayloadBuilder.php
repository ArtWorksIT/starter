<?php

namespace Artworksit\Starter\Console\Install;

use Closure;

class PayloadBuilder
{
    /**
     * @param array<int, array<string, string|array>> $pageEntries
     * @param Closure(string, array<string, string>): string $renderStub
     * @param Closure(string): string $stubPath
     * @param Closure(array<int, array<string, string|array>>): string $starterSeoSeederEntries
     * @param Closure(string): string $seoMigrationPath
     * @param Closure(string): string $blogMigrationPath
     * @param Closure(string): string $careersMigrationPath
     * @param Closure(string): string $updateRoutesWebContents
     */
    public function __construct(
        private array $pageEntries,
        private bool $installSeo,
        private bool $installBlog,
        private bool $installCareers,
        private ?string $careersMode,
        private Closure $renderStub,
        private Closure $stubPath,
        private Closure $starterSeoSeederEntries,
        private Closure $seoMigrationPath,
        private Closure $blogMigrationPath,
        private Closure $careersMigrationPath,
        private Closure $updateRoutesWebContents,
    ) {
    }

    /**
     * @return array<string, array{contents: string, allow_marker?: bool, always_write?: bool}>
     */
    public function build(): array
    {
        $routes = implode("\n", array_map(
            fn (array $entry): string => sprintf(
                "Route::get('%s', [WebsiteController::class, '%s'])->name('%s');",
                $entry['url'],
                $entry['controller_method'],
                $entry['route_name'],
            ),
            $this->pageEntries,
        ));

        if ($this->installBlog) {
            $routes .= "\n\nrequire __DIR__.'/blog.php';";
        }

        if ($this->installCareers) {
            $routes .= "\n\nrequire __DIR__.'/careers.php';";
        }

        $routesContents = $this->renderStub($this->stubPath('routes/starter.stub'), [
            'routes' => $routes,
        ]);

        $controllerMethods = implode("\n\n", array_map(
            function (array $entry): string {
                return sprintf(
                    "    public function %s(): \\Illuminate\\View\\View\n    {\n        return view('%s');\n    }",
                    $entry['controller_method'],
                    $entry['view'],
                );
            },
            $this->pageEntries,
        ));

        $controllerContents = $this->renderStub(
            $this->stubPath('controllers/WebsiteController.stub'),
            [
                'methods' => $controllerMethods,
            ],
        );

        $layoutContents = $this->renderStub(
            $this->stubPath('views/components/layout.stub'),
            [
                'slot' => '{{ $slot }}',
                'seo' => $this->installSeo ? '<x-seo />' : '<title>Starter</title>',
            ],
        );

        $headerContents = $this->renderStub(
            $this->stubPath('views/components/header.stub'),
            [
                'links' => $this->headerLinksMarkup($this->pageEntries, $this->installCareers),
            ],
        );

        $footerContents = $this->renderStub(
            $this->stubPath('views/components/footer.stub'),
            [
                'footerText' => '© '.date('Y').' Starter',
            ],
        );

        $pageStub = $this->stubPath('views/pages/page.stub');
        $pageFiles = [];

        foreach ($this->pageEntries as $entry) {
            $pageFiles[$entry['view_file']] = [
                'contents' => $this->renderStub($pageStub, [
                    'title' => $this->headline(
                        str_replace('.', ' ', $entry['key']),
                    ),
                ]),
            ];
        }

        $sharedTraitFiles = ($this->installSeo || $this->installBlog || $this->installCareers)
            ? $this->sharedTraitFilesPayload()
            : [];
        $seoFiles = $this->installSeo ? $this->seoFilesPayload($layoutContents) : [];
        $blogFiles = $this->installBlog ? $this->blogFilesPayload() : [];
        $careersFiles = $this->installCareers ? $this->careersFilesPayload($this->careersMode) : [];

        $payload = [
            'routes/starter.php' => [
                'contents' => $routesContents,
                'allow_marker' => true,
            ],
            'routes/web.php' => [
                'contents' => $this->updateRoutesWebContents('routes/web.php'),
                'allow_marker' => false,
                'always_write' => true,
            ],
            'app/Http/Controllers/WebsiteController.php' => [
                'contents' => $controllerContents,
                'allow_marker' => true,
            ],
            'resources/views/components/layout.blade.php' => [
                'contents' => $layoutContents,
                'allow_marker' => true,
            ],
            'resources/views/components/header.blade.php' => [
                'contents' => $headerContents,
                'allow_marker' => true,
            ],
            'resources/views/components/footer.blade.php' => [
                'contents' => $footerContents,
                'allow_marker' => true,
            ],
        ];

        $extras = array_merge($sharedTraitFiles, $seoFiles, $blogFiles, $careersFiles);

        return array_merge($payload, $extras, $pageFiles);
    }

    /**
     * @param array<int, array<string, string|array>> $pageEntries
     */
    private function headerLinksMarkup(array $pageEntries, bool $installCareers): string
    {
        $links = [];

        foreach ($pageEntries as $entry) {
            $links[] = sprintf(
                "<a href=\"{{ route('%s') }}\">%s</a>",
                $entry['route_name'],
                $this->headline(str_replace('.', ' ', $entry['key'])),
            );
        }

        if ($this->installBlog) {
            $links[] = "@if (Route::has('blog.index'))\n            <a href=\"{{ route('blog.index') }}\">Blog</a>\n        @endif";
        }

        if ($installCareers) {
            $links[] = "@if (Route::has('careers.index'))\n            <a href=\"{{ route('careers.index') }}\">Careers</a>\n        @endif";
        }

        return implode("\n        ", $links);
    }

    /**
     * @return array<string, array{contents: string, allow_marker?: bool, always_write?: bool}>
     */
    private function seoFilesPayload(string $layoutContents): array
    {
        $seoMigrationContents = $this->renderStub(
            $this->stubPath('seo/migration.stub'),
            [],
        );

        $seoModelContents = $this->renderStub(
            $this->stubPath('seo/model.stub'),
            [],
        );

        $seoResourceContents = $this->renderStub(
            $this->stubPath('seo/resource.stub'),
            [],
        );

        $seoListContents = $this->renderStub(
            $this->stubPath('seo/pages/ListSiteSeos.stub'),
            [],
        );

        $seoCreateContents = $this->renderStub(
            $this->stubPath('seo/pages/CreateSiteSeo.stub'),
            [],
        );

        $seoEditContents = $this->renderStub(
            $this->stubPath('seo/pages/EditSiteSeo.stub'),
            [],
        );

        $seoComponentContents = $this->renderStub(
            $this->stubPath('seo/seo-component.stub'),
            [],
        );

        $seoComponentClassContents = $this->renderStub(
            $this->stubPath('seo/components/Seo.stub'),
            [],
        );

        $starterSeederContents = $this->renderStub(
            $this->stubPath('seeders/StarterUserSeeder.stub'),
            [],
        );

        $seoSeederContents = $this->renderStub(
            $this->stubPath('seeders/StarterSeoSeeder.stub'),
            [
                'entries' => $this->starterSeoSeederEntries($this->pageEntries),
            ],
        );

        $migrationPath = $this->seoMigrationPath('create_site_seos_table');

        return [
            $migrationPath => [
                'contents' => $seoMigrationContents,
                'allow_marker' => true,
            ],
            'app/Models/SiteSeo.php' => [
                'contents' => $seoModelContents,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/SiteSeoResource.php' => [
                'contents' => $seoResourceContents,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/SiteSeoResource/Pages/ListSiteSeos.php' => [
                'contents' => $seoListContents,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/SiteSeoResource/Pages/CreateSiteSeo.php' => [
                'contents' => $seoCreateContents,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/SiteSeoResource/Pages/EditSiteSeo.php' => [
                'contents' => $seoEditContents,
                'allow_marker' => true,
            ],
            'resources/views/components/seo.blade.php' => [
                'contents' => $seoComponentContents,
                'allow_marker' => true,
            ],
            'app/View/Components/Seo.php' => [
                'contents' => $seoComponentClassContents,
                'allow_marker' => true,
            ],
            'resources/views/components/layout.blade.php' => [
                'contents' => $layoutContents,
                'allow_marker' => true,
            ],
            'database/seeders/StarterUserSeeder.php' => [
                'contents' => $starterSeederContents,
                'allow_marker' => true,
            ],
            'database/seeders/StarterSeoSeeder.php' => [
                'contents' => $seoSeederContents,
                'allow_marker' => true,
            ],
        ];
    }

    /**
     * @return array<string, array{contents: string, allow_marker?: bool, always_write?: bool}>
     */
    private function sharedTraitFilesPayload(): array
    {
        $seoMorphTraitContents = $this->renderStub(
            $this->stubPath('seo/models/concerns/HasSeoMorph.stub'),
            [],
        );

        $seoFormFieldsTraitContents = $this->renderStub(
            $this->stubPath('seo/filament/concerns/HasSeoFormFields.stub'),
            [],
        );

        $seoFormDataTraitContents = $this->renderStub(
            $this->stubPath('seo/filament/concerns/HandlesSeoFormData.stub'),
            [],
        );

        return [
            'app/Models/Concerns/HasSeoMorph.php' => [
                'contents' => $seoMorphTraitContents,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/Concerns/HasSeoFormFields.php' => [
                'contents' => $seoFormFieldsTraitContents,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/Concerns/HandlesSeoFormData.php' => [
                'contents' => $seoFormDataTraitContents,
                'allow_marker' => true,
            ],
        ];
    }

    /**
     * @return array<string, array{contents: string, allow_marker?: bool, always_write?: bool}>
     */
    private function blogFilesPayload(): array
    {
        $authorMigration = $this->renderStub(
            $this->stubPath('blog/migrations/create_blog_authors_table.stub'),
            [],
        );

        $postMigration = $this->renderStub(
            $this->stubPath('blog/migrations/create_blog_posts_table.stub'),
            [],
        );

        $authorModel = $this->renderStub(
            $this->stubPath('blog/models/BlogAuthor.stub'),
            [],
        );

        $postModel = $this->renderStub(
            $this->stubPath('blog/models/BlogPost.stub'),
            [],
        );

        $authorResource = $this->renderStub(
            $this->stubPath('blog/resources/blog-authors/resource.stub'),
            [],
        );

        $authorList = $this->renderStub(
            $this->stubPath('blog/resources/blog-authors/pages/ListBlogAuthors.stub'),
            [],
        );

        $authorCreate = $this->renderStub(
            $this->stubPath('blog/resources/blog-authors/pages/CreateBlogAuthor.stub'),
            [],
        );

        $authorEdit = $this->renderStub(
            $this->stubPath('blog/resources/blog-authors/pages/EditBlogAuthor.stub'),
            [],
        );

        $postResource = $this->renderStub(
            $this->stubPath('blog/resources/blog-posts/resource.stub'),
            [],
        );

        $postList = $this->renderStub(
            $this->stubPath('blog/resources/blog-posts/pages/ListBlogPosts.stub'),
            [],
        );

        $postCreate = $this->renderStub(
            $this->stubPath('blog/resources/blog-posts/pages/CreateBlogPost.stub'),
            [],
        );

        $postEdit = $this->renderStub(
            $this->stubPath('blog/resources/blog-posts/pages/EditBlogPost.stub'),
            [],
        );

        $blogRoutes = $this->renderStub(
            $this->stubPath('blog/routes/blog.stub'),
            [],
        );

        $blogController = $this->renderStub(
            $this->stubPath('blog/controllers/BlogController.stub'),
            [],
        );

        $blogIndex = $this->renderStub(
            $this->stubPath('blog/views/index.stub'),
            [],
        );

        $blogShow = $this->renderStub(
            $this->stubPath('blog/views/show.stub'),
            [],
        );

        $authorMigrationPath = $this->blogMigrationPath('create_blog_authors_table');
        $postMigrationPath = $this->blogMigrationPath('create_blog_posts_table');

        return [
            $authorMigrationPath => [
                'contents' => $authorMigration,
                'allow_marker' => true,
            ],
            $postMigrationPath => [
                'contents' => $postMigration,
                'allow_marker' => true,
            ],
            'app/Models/BlogAuthor.php' => [
                'contents' => $authorModel,
                'allow_marker' => true,
            ],
            'app/Models/BlogPost.php' => [
                'contents' => $postModel,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogAuthorResource.php' => [
                'contents' => $authorResource,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogAuthorResource/Pages/ListBlogAuthors.php' => [
                'contents' => $authorList,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogAuthorResource/Pages/CreateBlogAuthor.php' => [
                'contents' => $authorCreate,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogAuthorResource/Pages/EditBlogAuthor.php' => [
                'contents' => $authorEdit,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogPostResource.php' => [
                'contents' => $postResource,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogPostResource/Pages/ListBlogPosts.php' => [
                'contents' => $postList,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogPostResource/Pages/CreateBlogPost.php' => [
                'contents' => $postCreate,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/BlogPostResource/Pages/EditBlogPost.php' => [
                'contents' => $postEdit,
                'allow_marker' => true,
            ],
            'routes/blog.php' => [
                'contents' => $blogRoutes,
                'allow_marker' => true,
            ],
            'app/Http/Controllers/BlogController.php' => [
                'contents' => $blogController,
                'allow_marker' => true,
            ],
            'resources/views/blog/index.blade.php' => [
                'contents' => $blogIndex,
                'allow_marker' => true,
            ],
            'resources/views/blog/show.blade.php' => [
                'contents' => $blogShow,
                'allow_marker' => true,
            ],
        ];
    }

    /**
     * @return array<string, array{contents: string, allow_marker?: bool, always_write?: bool}>
     */
    private function careersFilesPayload(?string $careersMode): array
    {
        $jobMigration = $this->renderStub(
            $this->stubPath('careers/migrations/create_job_listings_table.stub'),
            [],
        );

        $jobFactory = $this->renderStub(
            $this->stubPath('careers/factories/JobListingFactory.stub'),
            [],
        );

        $jobSeeder = $this->renderStub(
            $this->stubPath('careers/seeders/JobListingSeeder.stub'),
            [],
        );

        $jobModel = $this->renderStub(
            $this->stubPath('careers/models/JobListing.stub'),
            [],
        );

        $jobResource = $this->renderStub(
            $this->stubPath('careers/resources/job-listings/resource.stub'),
            [
                'externalLinkRequired' => $careersMode === 'external' ? '->required()' : '',
            ],
        );

        $jobList = $this->renderStub(
            $this->stubPath('careers/resources/job-listings/pages/ListJobListings.stub'),
            [],
        );

        $jobCreate = $this->renderStub(
            $this->stubPath('careers/resources/job-listings/pages/CreateJobListing.stub'),
            [],
        );

        $jobEdit = $this->renderStub(
            $this->stubPath('careers/resources/job-listings/pages/EditJobListing.stub'),
            [],
        );

        $jobRoutes = $this->renderStub(
            $this->stubPath('careers/routes/careers.stub'),
            [],
        );

        $jobController = $this->renderStub(
            $this->stubPath('careers/controllers/JobController.stub'),
            [],
        );

        $careersIndex = $this->renderStub(
            $this->stubPath('careers/views/index.stub'),
            [],
        );

        $careersShow = $this->renderStub(
            $this->stubPath('careers/views/show.stub'),
            [],
        );

        $payload = [
            $this->careersMigrationPath('create_job_listings_table') => [
                'contents' => $jobMigration,
                'allow_marker' => true,
            ],
            'database/factories/JobListingFactory.php' => [
                'contents' => $jobFactory,
                'allow_marker' => true,
            ],
            'database/seeders/JobListingSeeder.php' => [
                'contents' => $jobSeeder,
                'allow_marker' => true,
            ],
            'app/Models/JobListing.php' => [
                'contents' => $jobModel,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/JobListingResource.php' => [
                'contents' => $jobResource,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/JobListingResource/Pages/ListJobListings.php' => [
                'contents' => $jobList,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/JobListingResource/Pages/CreateJobListing.php' => [
                'contents' => $jobCreate,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/JobListingResource/Pages/EditJobListing.php' => [
                'contents' => $jobEdit,
                'allow_marker' => true,
            ],
            'routes/careers.php' => [
                'contents' => $jobRoutes,
                'allow_marker' => true,
            ],
            'app/Http/Controllers/JobController.php' => [
                'contents' => $jobController,
                'allow_marker' => true,
            ],
            'resources/views/careers/index.blade.php' => [
                'contents' => $careersIndex,
                'allow_marker' => true,
            ],
            'resources/views/careers/show.blade.php' => [
                'contents' => $careersShow,
                'allow_marker' => true,
            ],
        ];

        if ($careersMode === 'internal') {
            $payload = array_merge($payload, $this->careersInternalFiles());
        }

        return $payload;
    }

    /**
     * @return array<string, array{contents: string, allow_marker?: bool, always_write?: bool}>
     */
    private function careersInternalFiles(): array
    {
        $submissionMigration = $this->renderStub(
            $this->stubPath('careers/migrations/create_job_submissions_table.stub'),
            [],
        );

        $submissionModel = $this->renderStub(
            $this->stubPath('careers/models/JobSubmission.stub'),
            [],
        );

        $submissionResource = $this->renderStub(
            $this->stubPath('careers/resources/job-submissions/resource.stub'),
            [],
        );

        $submissionList = $this->renderStub(
            $this->stubPath('careers/resources/job-submissions/pages/ListJobSubmissions.stub'),
            [],
        );

        $submissionView = $this->renderStub(
            $this->stubPath('careers/resources/job-submissions/pages/ViewJobSubmission.stub'),
            [],
        );

        $applyView = $this->renderStub(
            $this->stubPath('careers/views/apply.stub'),
            [],
        );

        $applyComponent = $this->renderStub(
            $this->stubPath('careers/livewire/ApplyJob.stub'),
            [],
        );

        $applyComponentView = $this->renderStub(
            $this->stubPath('careers/views/livewire-apply.stub'),
            [],
        );

        return [
            $this->careersMigrationPath('create_job_submissions_table') => [
                'contents' => $submissionMigration,
                'allow_marker' => true,
            ],
            'app/Models/JobSubmission.php' => [
                'contents' => $submissionModel,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/JobSubmissionResource.php' => [
                'contents' => $submissionResource,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/JobSubmissionResource/Pages/ListJobSubmissions.php' => [
                'contents' => $submissionList,
                'allow_marker' => true,
            ],
            'app/Filament/Resources/JobSubmissionResource/Pages/ViewJobSubmission.php' => [
                'contents' => $submissionView,
                'allow_marker' => true,
            ],
            'resources/views/careers/apply.blade.php' => [
                'contents' => $applyView,
                'allow_marker' => true,
            ],
            'app/Livewire/Careers/ApplyJob.php' => [
                'contents' => $applyComponent,
                'allow_marker' => true,
            ],
            'resources/views/livewire/careers/apply-job.blade.php' => [
                'contents' => $applyComponentView,
                'allow_marker' => true,
            ],
        ];
    }

    /**
     * @param array<string, string> $replacements
     */
    private function renderStub(string $path, array $replacements): string
    {
        return ($this->renderStub)($path, $replacements);
    }

    private function stubPath(string $path): string
    {
        return ($this->stubPath)($path);
    }

    /**
     * @param array<int, array<string, string|array>> $entries
     */
    private function starterSeoSeederEntries(array $entries): string
    {
        return ($this->starterSeoSeederEntries)($entries);
    }

    private function seoMigrationPath(string $suffix): string
    {
        return ($this->seoMigrationPath)($suffix);
    }

    private function blogMigrationPath(string $suffix): string
    {
        return ($this->blogMigrationPath)($suffix);
    }

    private function careersMigrationPath(string $suffix): string
    {
        return ($this->careersMigrationPath)($suffix);
    }

    private function updateRoutesWebContents(string $relativePath): string
    {
        return ($this->updateRoutesWebContents)($relativePath);
    }

    private function headline(string $value): string
    {
        $normalized = str_replace(['.', '-', '_'], ' ', $value);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        if (! is_string($normalized) || $normalized === '') {
            return '';
        }

        return ucwords($normalized);
    }
}
