<?php
namespace Drush\Commands\core;

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Drush\SiteAlias\HostPath;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Drush\SiteAlias\SiteAliasManagerAwareTrait;

class RsyncCommands extends DrushCommands implements SiteAliasManagerAwareInterface
{
    use SiteAliasManagerAwareTrait;

    /**
     * These are arguments after the aliases and paths have been evaluated.
     * @see validate().
     */
    /** @var HostPath */
    public $source_evaluated_path;
    /** @var HostPath */
    public $destination_evaluated_path;

    /**
     * Rsync Drupal code or files to/from another server using ssh.
     *
     * @command core-rsync
     * @param $source A site alias and optional path. See rsync documentation and example.aliases.drushrc.php.
     * @param $destination A site alias and optional path. See rsync documentation and example.aliases.drushrc.php.',
     * @param $extra Additional parameters after the ssh statement.
     * @optionset_ssh
     * @option exclude-paths List of paths to exclude, seperated by : (Unix-based systems) or ; (Windows).
     * @option include-paths List of paths to include, seperated by : (Unix-based systems) or ; (Windows).
     * @option mode The unary flags to pass to rsync; --mode=rultz implies rsync -rultz.  Default is -akz.
     * @usage drush rsync @dev @stage
     *   Rsync Drupal root from Drush alias dev to the alias stage.
     * @usage drush rsync ./ @stage:%files/img
     *   Rsync all files in the current directory to the 'img' directory in the file storage folder on the Drush alias stage.
     * @usage drush rsync @dev @stage -- --exclude=*.sql --delete
     *   Rsync Drupal root from the Drush alias dev to the alias stage, excluding all .sql files and delete all files on the destination that are no longer on the source.
     * @usage drush rsync @dev @stage --ssh-options="-o StrictHostKeyChecking=no" -- --delete
     *   Customize how rsync connects with remote host via SSH. rsync options like --delete are placed after a --.
     * @aliases rsync
     * @topics docs-aliases
     * @complete \Drush\Commands\CompletionCommands::completeSiteAliases
     */
    public function rsync($source, $destination, array $extra, $options = ['exclude-paths' => null, 'include-paths' => null, 'mode' => 'akz'])
    {
        // Prompt for confirmation. This is destructive.
        if (!\Drush\Drush::simulate()) {
            drush_print(dt("You will delete files in !target and replace with data from !source", array('!source' => $this->source_evaluated_path, '!target' => $this->destination_evaluated_path)));
            if (!$this->io()->confirm(dt('Do you want to continue?'))) {
                throw new UserAbortException();
            }
        }

        $rsync_options = $this->rsyncOptions($options);
        $parameters = array_merge([$rsync_options], $extra);
        $parameters[] = $this->source_evaluated_path->fullyQualifiedPath();
        $parameters[] = $this->destination_evaluated_path->fullyQualifiedPath();

        $ssh_options = \Drush\Drush::config()->get('ssh.options', '');
        $exec = "rsync -e 'ssh $ssh_options'". ' '. implode(' ', array_filter($parameters));
        $exec_result = drush_op_system($exec);

        if ($exec_result == 0) {
            drush_backend_set_result($this->destination_evaluated_path->fullyQualifiedPath());
        } else {
            throw new \Exception(dt("Could not rsync from !source to !dest", array('!source' => $this->source_evaluated_path, '!dest' => $this->destination_evaluated_path)));
        }
    }

    public function rsyncOptions($options)
    {
        $verbose = $paths = '';
        // Process --include-paths and --exclude-paths options the same way
        foreach (array('include', 'exclude') as $include_exclude) {
            // Get the option --include-paths or --exclude-paths and explode to an array of paths
            // that we will translate into an --include or --exclude option to pass to rsync
            $inc_ex_path = explode(PATH_SEPARATOR, @$options[$include_exclude . '-paths']);
            foreach ($inc_ex_path as $one_path_to_inc_ex) {
                if (!empty($one_path_to_inc_ex)) {
                    $paths = ' --' . $include_exclude . '="' . $one_path_to_inc_ex . '"';
                }
            }
        }

        $mode = '-'. $options['mode'];
        if ($this->output()->isVerbose()) {
            $mode .= 'v';
            $verbose = ' --stats --progress';
        }

        return implode(' ', array_filter([$mode, $verbose, $paths]));
    }

    /**
     * Validate that passed aliases are valid.
     *
     * @hook validate core-rsync
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     * @throws \Exception
     * @return void
     */
    public function validate(CommandData $commandData)
    {
        $destination = $commandData->input()->getArgument('destination');
        $source = $commandData->input()->getArgument('source');

        $manager = $this->siteAliasManager();
        $this->source_evaluated_path = HostPath::create($manager, $source);
        $this->destination_evaluated_path = HostPath::create($manager, $destination);

        if ($this->source_evaluated_path->isRemote() && $this->destination_evaluated_path->isRemote()) {
            $msg = dt('Cannot specify two remote aliases. Instead, use this form: `drush !source rsync @self !target`. Make sure site alias definitions are available at !source', array('!source' => $source, '!target' => $destination));
            throw new \Exception($msg);
        }

    }
}
