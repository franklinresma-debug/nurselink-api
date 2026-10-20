"""Validate production inputs without production credentials; fail closed."""
import json
import os
import re
import urllib.request

OWNER = 'franklinresma-debug'


def github(path):
    request = urllib.request.Request('https://api.github.com/' + path, headers={
        'Authorization': 'Bearer ' + os.environ['GH_TOKEN'],
        'Accept': 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
    })
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def check_gate(environment, branches):
    reviewers = [rule for rule in environment.get('protection_rules', [])
                 if rule.get('type') == 'required_reviewers' and rule.get('reviewers')]
    if not reviewers or environment.get('can_admins_bypass') is not False:
        raise ValueError('production must require approval and disallow administrator bypass')
    policy = environment.get('deployment_branch_policy') or {}
    rules = branches.get('branch_policies', [])
    if not policy.get('custom_branch_policies') or len(rules) != 1 or rules[0].get('name') != 'main' or rules[0].get('type') != 'branch':
        raise ValueError('production must allow only the main branch')


def main():
    commits = {'backend': ('nurselink-api', os.environ['BACKEND_SHA']),
               'frontend': ('lbs-nurselink-web', os.environ['FRONTEND_SHA'])}
    for name, (repo, sha) in commits.items():
        if not re.fullmatch('[0-9a-f]{40}', sha):
            raise ValueError(name + ' must be a full lowercase commit SHA')
        comparison = github(f'repos/{OWNER}/{repo}/compare/{sha}...main')
        if comparison['status'] not in ['ahead', 'identical']:
            raise ValueError(name + ' commit is not contained in main')
    environment = f'repos/{OWNER}/nurselink-api/environments/production'
    check_gate(github(environment), github(environment + '/deployment-branch-policies'))
    with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
        for name, (_, sha) in commits.items():
            output.write(f'{name}={sha}\n')
    print('Both exact versions belong to main; the production approval gate is configured.')


if __name__ == '__main__':
    main()
