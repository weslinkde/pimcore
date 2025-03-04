/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

pimcore.registerNS("pimcore.settings.properties.predefined");
pimcore.settings.properties.predefined = Class.create({

    initialize: function () {
        this.getTabPanel();
    },

    activate: function () {
        var tabPanel = Ext.getCmp("pimcore_panel_tabs");
        tabPanel.setActiveItem("predefined_properties");
    },

    getTabPanel: function () {

        if (!this.panel) {
            this.panel = new Ext.Panel({
                id: "predefined_properties",
                title: t("predefined_properties"),
                iconCls: "pimcore_icon_properties",
                border: false,
                layout: "fit",
                closable:true,
                items: [this.getRowEditor()]
            });

            var tabPanel = Ext.getCmp("pimcore_panel_tabs");
            tabPanel.add(this.panel);
            tabPanel.setActiveItem("predefined_properties");


            this.panel.on("destroy", function () {
                pimcore.globalmanager.remove("predefined_properties");
            }.bind(this));

            pimcore.layout.refresh();
        }

        return this.panel;
    },

    getRowEditor: function () {

        var url = Routing.generate('pimcore_admin_settings_properties');

        this.store = pimcore.helpers.grid.buildDefaultStore(
            url,
            ['id',

                {name: 'name', allowBlank: false},'description',
                {name: 'key', allowBlank: false},
                {name: 'type', allowBlank: false}, 'data', 'config',
                {name: 'ctype', allowBlank: false}, 'inheritable', 'creationDate', 'modificationDate'

            ], null, {
                remoteSort: false,
                remoteFilter: false
            }
        );
        this.store.setAutoSync(true);

        this.filterField = new Ext.form.TextField({
            width: 200,
            style: "margin: 0 10px 0 0;",
            enableKeyEvents: true,
            listeners: {
                "keydown" : function (field, key) {
                    if (key.getKey() == key.ENTER) {
                        var input = field;
                        var proxy = this.store.getProxy();
                        proxy.extraParams.filter = input.getValue();
                        this.store.load();
                    }
                }.bind(this)
            }
        });

        var inheritableCheck = new Ext.grid.column.Check({
            text: t("inheritable"),
            dataIndex: "inheritable",
            editor: {
                xtype: 'checkbox',
            },
            width: 50
        });

        var contentTypesStore = Ext.create('Ext.data.ArrayStore', {
            fields: ['value', 'text'],
            data: [
                ['document', 'document'],
                ['asset', 'asset'],
                ['object', 'object']
            ],
            autoLoad: true
        });


        var propertiesColumns = [
            {text: t("name"), flex: 100, sortable: true, dataIndex: 'name', editor: new Ext.form.TextField({})},
            {text: t("description"), sortable: true, dataIndex: 'description', editor: new Ext.form.TextArea({}),
                renderer: function (value, metaData, record, rowIndex, colIndex, store) {
                    if(empty(value)) {
                        return "";
                    }
                    return nl2br(Ext.util.Format.htmlEncode(value));
               }
            },
            {text: t("key"), flex: 50, sortable: true, dataIndex: 'key', editor: new Ext.form.TextField({})},
            {text: t("type"), flex: 50, sortable: true, dataIndex: 'type',
                editor: new Ext.form.ComboBox({
                    triggerAction: 'all',
                    editable: false,
                    store: ["text","document","asset","object","bool","select"]

            })},
            {text: t("value"), flex: 50, sortable: true, dataIndex: 'data', editor: new Ext.form.TextField({})},
            {text: t("configuration"), flex: 50, sortable: false, dataIndex: 'config', editor: new Ext.form.TextField({})},
            {text: t("content_type"), flex: 50, sortable: true, dataIndex: 'ctype',
                editor: new Ext.ux.form.MultiSelect({
                    store: new Ext.data.ArrayStore({
                        fields: ['key', {
                            name: 'value',
                            convert: function (v, r) {
                                if (Array.isArray(v)) {
                                    return v.join(";");
                                }
                                return v;
                            }
                        }],
                        data: [
                            ['document', 'document'],
                            ['object', ['object']],
                            ['asset', ['asset']]
                        ],
                    }),
                    displayField: 'key',
                    valueField: 'value',
                }),
            },
            inheritableCheck,
            {
                xtype: 'actioncolumn',
                menuText: t('delete'),
                width: 30,
                items: [{
                    getClass: function(v, meta, rec) {
                      var klass = "pimcore_action_column ";
                      if(rec.data.writeable) {
                          klass += "pimcore_icon_minus";
                      }
                      return klass;
                    },
                    tooltip: t('delete'),
                    handler: function (grid, rowIndex) {
                        let data = grid.getStore().getAt(rowIndex);
                        pimcore.helpers.deleteConfirm(t('predefined_properties'), data.data.name, function () {
                            grid.getStore().removeAt(rowIndex);
                        }.bind(this));
                    }.bind(this)
                }]
            },{
                xtype: 'actioncolumn',
                menuText: t('translate'),
                width: 30,
                items: [{
                    tooltip: t('translate'),
                    icon: "/bundles/pimcoreadmin/img/flat-color-icons/collaboration.svg",
                    handler: function(grid, rowIndex){
                        var rec = grid.getStore().getAt(rowIndex);
                        try {
                            pimcore.globalmanager.get("translationadminmanager").activate(rec.data.name);
                        }
                        catch (e) {
                            pimcore.globalmanager.add("translationadminmanager",
                                                        new pimcore.settings.translation.admin(rec.data.name));
                        }
                    }.bind(this)
                }]
            },
            {text: t("creationDate"), sortable: true, dataIndex: 'creationDate', editable: false,
                hidden: true,
                renderer: function(d) {
                    if (d !== undefined) {
                        var date = new Date(d * 1000);
                        return Ext.Date.format(date, "Y-m-d H:i:s");
                    } else {
                        return "";
                    }
                }
            },
            {text: t("modificationDate"), sortable: true, dataIndex: 'modificationDate', editable: false,
                hidden: true,
                renderer: function(d) {
                    if (d !== undefined) {
                        var date = new Date(d * 1000);
                        return Ext.Date.format(date, "Y-m-d H:i:s");
                    } else {
                        return "";
                    }
                }
            }

        ];

        this.rowEditing = Ext.create('Ext.grid.plugin.RowEditing', {
            clicksToEdit: 1,
            clicksToMoveEditor: 1,
            listeners: {
                beforeedit: function (editor, context, eOpts) {
                    if (!context.record.data.writeable) {
                        return false;
                    }
                }
            }
        });

        this.grid = Ext.create('Ext.grid.Panel', {
            frame: false,
            autoScroll: true,
            store: this.store,
            columnLines: true,
            bodyCls: "pimcore_editable_grid",
            stripeRows: true,
            trackMouseOver: true,
            columns: {
                items: propertiesColumns,
                defaults: {
                    renderer: Ext.util.Format.htmlEncode
                },
            },
            selModel: Ext.create('Ext.selection.RowModel', {}),
            plugins: [
                this.rowEditing
            ],
            tbar: {
                cls: 'pimcore_main_toolbar',
                items: [
                    {
                        text: t('add'),
                        handler: this.onAdd.bind(this),
                        iconCls: "pimcore_icon_add",
                        disabled: !pimcore.settings['predefined-properties-writeable']
                    },"->",{
                        text: t("filter") + "/" + t("search"),
                        xtype: "tbtext",
                        style: "margin: 0 10px 0 0;"
                    },
                    this.filterField
                ]
            },
            viewConfig: {
                forceFit: true,
                getRowClass: function (record, rowIndex) {
                    return record.data.writeable ? '' : 'pimcore_grid_row_disabled';
                }
            }
        });

        return this.grid;
    },

    onAdd: function (btn, ev) {
        this.grid.store.insert(0, {
            name: t('new_property'),
            key: "new_key",
            ctype: "document",
            type: "text"
        });
    }
});
