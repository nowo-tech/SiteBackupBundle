DEMOS := symfony8

.PHONY: help clean test-all verify-all release-check release-verify update-bundle-all
.PHONY: $(foreach demo,$(DEMOS),up-$(demo) down-$(demo) restart-$(demo) build-$(demo) install-$(demo) update-bundle-$(demo) shell-$(demo) logs-$(demo) test-$(demo) verify-$(demo))
.PHONY: up down restart build install update-bundle test shell logs verify

help:
	@echo "Site Backup Mode Bundle - Demo Commands"
	@echo ""
	@echo "  make up-symfony8 / down-symfony8 / test-symfony8 / verify-symfony8"
	@echo "  make release-check"
	@echo ""
	@echo "Access: Symfony 8 http://localhost:8056"

define target_template
$(1)-$(2):
	@$(MAKE) -C $(2) $(1)
endef

$(foreach d,$(DEMOS),$(eval $(call target_template,up,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,down,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,restart,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,build,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,install,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,update-bundle,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,shell,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,logs,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,test,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,verify,$(d))))

test-all:
	@$(foreach d,$(DEMOS),$(MAKE) test-$(d);)

verify-all:
	@$(foreach d,$(DEMOS),$(MAKE) verify-$(d);)

update-bundle-all:
	@$(foreach d,$(DEMOS),$(MAKE) update-bundle-$(d);)

up:
	@[ -n "$(DEMO)" ] || { echo "Usage: make up DEMO=symfony8"; exit 1; }
	@$(MAKE) up-$(DEMO)

down:
	@[ -n "$(DEMO)" ] || { echo "Usage: make down DEMO=symfony8"; exit 1; }
	@$(MAKE) down-$(DEMO)

test:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test DEMO=symfony8"; exit 1; }
	@$(MAKE) test-$(DEMO)

release-verify:
	@$(foreach d,$(DEMOS),$(MAKE) up-$(d) && $(MAKE) verify-$(d) && $(MAKE) down-$(d);)

release-check: update-bundle-all test-all release-verify

BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# REQ-MAKE-008: update-deps-all
include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
